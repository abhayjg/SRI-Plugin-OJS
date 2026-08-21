<?php

/**
 * @file plugins/pubIds/sri/SriPubIdPlugin.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * @class SriPubIdPlugin
 * @ingroup plugins_pubIds_sri
 *
 * @brief SRI-Plugin — registers Scitekhub Research Identifiers (SRI) for OJS
 * articles at publication time, via the SRI REST API.
 *
 * Implements Phase 2 / Strategy A of the OJS Integration & Metadata Standards
 * plan: a standalone `pubIds` plugin (extending PKPPubIdPlugin — the same base
 * the official DOI plugin and the shipped ARK/PURL/URN plugins extend) that is
 * a thin client to SRI-Backend's POST /api/v1/register API keyed with
 * X-SRI-API-Key. Registration is always best-effort and never blocks publish.
 */

namespace APP\plugins\pubIds\sri;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\submission\Submission;
use PKP\components\forms\FieldHTML;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RemoteActionConfirmationModal;
use PKP\plugins\Hook;
use PKP\plugins\PKPPubIdPlugin;
use PKP\template\PKPTemplateManager;
use SRI\Plugin\ApiClient;
use SRI\Plugin\ArticleData;
use SRI\Plugin\CheckCharacter;
use SRI\Plugin\MetadataMapper;
use SRI\Plugin\RegistrationService;
use SRI\Plugin\StatePresenter;
use SRI\Plugin\StatusResolver;
use SRI\Plugin\SuffixGenerator;

class SriPubIdPlugin extends PKPPubIdPlugin
{
    /** OJS-visible identifier/class type (folder/class name must match OJS rules). */
    public const PUB_ID_TYPE = 'sri';

    /** Public display string. The project/plugin name is SRI-Plugin. */
    public const DISPLAY_TYPE = 'SRI-Plugin';

    public const VERSION = '1.0.3';

    /** @var bool Ensures the shared SRI\Plugin\ core is loaded once. */
    private bool $_coreBootstrapped = false;

    /** @var string Hooks registered on this request (prevent double registration). */
    private static array $_registeredHooks = [];

    //
    // Plugin identity -----------------------------------------------------------
    //

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.pubIds.sri.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.pubIds.sri.description');
    }

    //
    // Registration --------------------------------------------------------------
    //

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        if (Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        $this->bootstrapCore();

        if (!in_array('publish', self::$_registeredHooks, true)) {
            Hook::add('Publication::publish', [$this, 'onPublicationPublish']);
            self::$_registeredHooks[] = 'publish';
        }
        if (!in_array('articleView', self::$_registeredHooks, true)) {
            Hook::add('ArticleHandler::view', [$this, 'onArticleView']);
            self::$_registeredHooks[] = 'articleView';
        }
        if (!in_array('identifiersForm', self::$_registeredHooks, true)) {
            Hook::add('Form::config::before', [$this, 'addPublicationFormFields']);
            self::$_registeredHooks[] = 'identifiersForm';
        }

        return $success;
    }

    /**
     * Ensure the shared SRI\Plugin\ core (classes/core/) is autoloadable.
     */
    private function bootstrapCore(): void
    {
        if ($this->_coreBootstrapped) {
            return;
        }
        $bootstrap = dirname(__FILE__) . '/classes/bootstrap.php';
        if (is_file($bootstrap)) {
            require_once($bootstrap);
        } elseif (!class_exists(ApiClient::class)) {
            // The build must copy src/ into classes/core/ (see build/package.php).
            error_log('[SRI-Plugin] Shared core missing under classes/core/ — run php build/package.php. SRI registration disabled.');
        }
        $this->_coreBootstrapped = true;
    }

    //
    // Abstract PKPPubIdPlugin members ------------------------------------------
    //

    /**
     * @copydoc PKPPubIdPlugin::getPubId()
     *
     * Returns the stored SRI (full identifier) for the object. SRI identifiers
     * are minted server-side by the API at registration time — there is no
     * local pre-assignment step, so before successful registration this
     * returns null (nothing stored, nothing claimed).
     */
    public function getPubId($pubObject)
    {
        if (method_exists($pubObject, 'getStoredPubId')) {
            return $pubObject->getStoredPubId(self::PUB_ID_TYPE);
        }
        if (method_exists($pubObject, 'getData')) {
            $value = $pubObject->getData('pub-id::' . self::PUB_ID_TYPE);
            return is_string($value) && $value !== '' ? $value : null;
        }
        return null;
    }

    /**
     * @copydoc PKPPubIdPlugin::constructPubId()
     *
     * Reconstructs a full SRI from the configured numeric prefix and a suffix.
     * The year is taken from the current year (constructPubId has no object
     * context); this is only used for suffix-edit preview/duplicate checks,
     * never for the authoritative registration, which uses the year derived
     * from the article's publication date (see SriMetadataBuilder).
     */
    public function constructPubId($pubIdPrefix, $pubIdSuffix, $contextId)
    {
        $year = (int)date('Y');
        $prefix = (int)preg_replace('/\D+/', '', (string)$pubIdPrefix);
        if ($prefix <= 0) {
            return '';
        }
        return CheckCharacter::buildSri($year, $prefix, (string)$pubIdSuffix);
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdType()
     */
    public function getPubIdType()
    {
        return self::PUB_ID_TYPE;
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdDisplayType()
     */
    public function getPubIdDisplayType()
    {
        return self::DISPLAY_TYPE;
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdFullName()
     */
    public function getPubIdFullName()
    {
        return __('plugins.pubIds.sri.fullName');
    }

    /**
     * @copydoc PKPPubIdPlugin::getResolvingURL()
     */
    public function getResolvingURL($contextId, $pubId)
    {
        $origin = $this->getResolverOrigin($contextId);
        $pubId = (string)$pubId;
        $path = str_starts_with($pubId, 'sri:') ? substr($pubId, 4) : $pubId;
        return rtrim($origin, '/') . '/sri/' . $path;
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdMetadataFile()
     */
    public function getPubIdMetadataFile()
    {
        return $this->getTemplateResource('identifierStatus.tpl');
    }

    /**
     * @copydoc PKPPubIdPlugin::addJavaScript()
     */
    public function addJavaScript($request, $templateMgr)
    {
        // No JS needed: actions use core LinkAction handlers.
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdAssignFile()
     */
    public function getPubIdAssignFile()
    {
        return $this->getTemplateResource('identifierStatus.tpl');
    }

    /**
     * @copydoc PKPPubIdPlugin::instantiateSettingsForm()
     */
    public function instantiateSettingsForm($contextId)
    {
        $form = new form\SriSettingsForm($this, $contextId);
        return $form;
    }

    /**
     * @copydoc PKPPubIdPlugin::getFormFieldNames()
     */
    public function getFormFieldNames()
    {
        return ['sriSuffix'];
    }

    /**
     * @copydoc PKPPubIdPlugin::getAssignFormFieldName()
     */
    public function getAssignFormFieldName()
    {
        return 'assignSri';
    }

    /**
     * @copydoc PKPPubIdPlugin::getPrefixFieldName()
     */
    public function getPrefixFieldName()
    {
        return 'sriPrefix';
    }

    /**
     * @copydoc PKPPubIdPlugin::getSuffixFieldName()
     */
    public function getSuffixFieldName()
    {
        return 'sriSuffix';
    }

    /**
     * @copydoc PKPPubIdPlugin::getSuffixPatternsFieldNames()
     */
    public function getSuffixPatternsFieldNames()
    {
        return [
            'Publication' => 'sriPublicationSuffixPattern',
            'Representation' => 'sriRepresentationSuffixPattern',
        ];
    }

    /**
     * @copydoc PKPPubIdPlugin::getDAOFieldNames()
     */
    public function getDAOFieldNames()
    {
        return ['pub-id::' . self::PUB_ID_TYPE];
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubObjectTypes()
     */
    public function getPubObjectTypes()
    {
        return [
            'Publication' => '\APP\publication\Publication',
            'Representation' => '\PKP\submission\Representation',
        ];
    }

    /**
     * @copydoc PKPPubIdPlugin::isObjectTypeEnabled()
     */
    public function isObjectTypeEnabled($pubObjectType, $contextId)
    {
        if ($pubObjectType === 'Publication') {
            return (bool)$this->setting($contextId, 'enablePublicationSri', true);
        }
        return (bool)$this->setting($contextId, 'enableRepresentationSri', false);
    }

    /**
     * @copydoc PKPPubIdPlugin::getNotUniqueErrorMsg()
     */
    public function getNotUniqueErrorMsg()
    {
        return __('plugins.pubIds.sri.editor.suffixNotUnique');
    }

    /**
     * @copydoc PKPPubIdPlugin::getLinkActions()
     */
    public function getLinkActions($pubObject)
    {
        $linkActions = [];
        $request = Application::get()->getRequest();
        $userVars = $request->getUserVars();
        $userVars['pubIdPlugIn'] = get_class($this);

        $objectId = method_exists($pubObject, 'getId') ? $pubObject->getId() : null;
        $submissionId = null;
        if ($pubObject instanceof Submission) {
            $submissionId = $pubObject->getId();
        } elseif (method_exists($pubObject, 'getData')) {
            $submissionId = $pubObject->getData('submissionId');
        }

        $actionArgs = [
            'verb' => 'clearPubId',
            'category' => 'pubIds',
            'plugin' => $this->getName(),
            'pubObjectId' => $objectId,
            'submissionId' => $submissionId,
            'csrfToken' => $request->getSession() ? $request->getSession()->getCSRFToken() : null,
        ];

        $linkActions['clearPubIdLinkActionSri'] = new LinkAction(
            'clearPubId',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.pubIds.sri.editor.clearObjectsSri.confirm'),
                __('common.delete'),
                $request->url(null, null, 'manage', null, $actionArgs),
                'modal_delete'
            ),
            __('plugins.pubIds.sri.editor.clearObjectsSri'),
            'delete'
        );

        return $linkActions;
    }

    /**
     * "Clear all SRIs" link action surfaced in the plugin settings form
     * (deletes every locally stored SRI for this plugin; the verb handled by
     * PKPPubIdPlugin::manage clearPubIds).
     */
    public function getClearAllLinkAction(int $contextId): LinkAction
    {
        $request = Application::get()->getRequest();
        return new LinkAction(
            'clearAllSri',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.pubIds.sri.manager.settings.clearAll.confirm'),
                __('common.delete'),
                $request->url(null, null, 'manage', null, [
                    'verb' => 'clearPubIds',
                    'category' => 'pubIds',
                    'plugin' => $this->getName(),
                ]),
                'modal_delete'
            ),
            __('plugins.pubIds.sri.manager.settings.clearAll'),
            'delete'
        );
    }

    /**
     * "Register back catalog" link action opened from the plugin settings
     * (AjaxModal -> manage verb 'bulk').
     */
    public function getBulkLinkAction(int $contextId): LinkAction
    {
        $request = Application::get()->getRequest();
        return new LinkAction(
            'sriBulk',
            new AjaxModal(
                $request->url(null, null, 'manage', null, [
                    'verb' => 'bulk',
                    'category' => 'pubIds',
                    'plugin' => $this->getName(),
                ]),
                __('plugins.pubIds.sri.bulk.title')
            ),
            __('plugins.pubIds.sri.bulk.title')
        );
    }

    //
    // Settings helpers ------------------------------------------------------------
    //

    /**
     * Read a plugin setting with a default.
     */
    public function setting(int $contextId, string $name, $default = null)
    {
        $value = $this->getSetting($contextId, $name);
        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * Build the ApiClient from settings (hardened timeouts + TLS, UA tagged).
     */
    public function getApiClient(int $contextId): ApiClient
    {
        $baseUrl = (string)$this->setting($contextId, 'sriBaseUrl', '');
        $apiKey = (string)$this->setting($contextId, 'sriApiKey', '');
        $connectTimeout = max(1, (int)$this->setting($contextId, 'sriConnectTimeout', 10));
        $timeout = max(1, (int)$this->setting($contextId, 'sriTimeout', 30));

        $application = Application::get();
        $version = '';
        if (method_exists($application, 'getVersion') && $application->getVersion()) {
            try {
                $version = $application->getVersion()->getVersionString();
            } catch (\Throwable $e) {
                $version = '';
            }
        } elseif (defined('APP_VERSION')) {
            $version = (string)APP_VERSION;
        }
        $ua = 'SRI-Plugin/' . self::VERSION . ' (+OJS ' . $version . '; SRI-Plugin; +https://scitekhub.com)';

        return new ApiClient($baseUrl, $apiKey, $connectTimeout, $timeout, null, $ua);
    }

    /**
     * Build the shared RegistrationService.
     */
    public function getService(int $contextId): RegistrationService
    {
        return new RegistrationService($this->getApiClient($contextId));
    }

    /**
     * Derive the resolver origin from settings (or the API base URL).
     */
    public function getResolverOrigin(int $contextId): string
    {
        $resolver = trim((string)$this->setting($contextId, 'sriResolverUrl', ''));
        if ($resolver === '') {
            $resolver = (string)$this->setting($contextId, 'sriBaseUrl', '');
            // The plugin's base URL points at the API (/api/v1); the resolver
            // lives at the public origin: /sri/{fullSri}.
            $resolver = (string)preg_replace('#/api/v1/?$#', '', $resolver);
        }
        return rtrim($resolver, '/');
    }

    //
    // Result & status persistence -------------------------------------------------
    //

    /**
     * Keyed status storage per submission (mirrors a registration outcome so
     * the status badge survives page reloads without an extra API call).
     */
    public function writeStatus(int $contextId, int $submissionId, array $status): void
    {
        $status['updatedAt'] = date('c');
        $this->updateSetting($contextId, $this->statusKey($submissionId), (string)json_encode($status), 'string');
    }

    public function readStatus(int $contextId, int $submissionId): ?array
    {
        $raw = $this->getSetting($contextId, $this->statusKey($submissionId));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function clearStatus(int $contextId, int $submissionId): void
    {
        $this->updateSetting($contextId, $this->statusKey($submissionId), '', 'string');
    }

    private function statusKey(int $submissionId): string
    {
        return 'sriStatus:' . $submissionId;
    }

    //
    // Core registration flow (shared by hook + manual actions) --------------------
    //

    /**
     * Attempt registration for a submission. Best-effort: never throws; always
     * persists a status outcome. Returns the result array (see
     * RegistrationService::register / registerWithRetry).
     */
    public function registerSubmission(int $contextId, int $submissionId, bool $retryOnConflict = true): array
    {
        try {
            $submission = Repo::submission()->get($submissionId);
            if (!$submission) {
                throw new \Exception('Submission not found');
            }
            if ((int)$submission->getData('contextId') !== $contextId) {
                throw new \Exception('Submission belongs to another context');
            }

            $article = $this->metadataBuilder()->fromSubmission($submission);
            $suffix = $this->computeSuffix($contextId, $submission, $article);
            $prefix = (int)$this->setting($contextId, 'sriPrefix', 0);
            if ($prefix <= 0) {
                throw new \Exception('SRI prefix is not configured');
            }

            $service = $this->getService($contextId);
            $result = $retryOnConflict ? $service->registerWithRetry($article, $prefix, $suffix) : $service->register($article, $prefix, $suffix);

            $this->persistResult($contextId, $submission, $result, $article);
            return $result;
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] registerSubmission failed for submission ' . $submissionId . ': ' . $e->getMessage());
            $result = [
                'success' => false,
                'state' => StatusResolver::STATE_FAILED,
                'httpStatus' => 0,
                'code' => 'PLUGIN_ERROR',
                'message' => $e->getMessage(),
                'reason' => (new StatusResolver())->resolveTransportError($e->getMessage()),
            ];
            $this->writeStatus($contextId, $submissionId, [
                'state' => $result['state'],
                'fullSri' => '',
                'reasonKey' => $result['reason']['key'],
                'reasonParams' => $result['reason']['params'],
                'message' => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * After a registration attempt, persist the SRI + status.
     */
    private function persistResult(int $contextId, Submission $submission, array $result, ArticleData $article): void
    {
        $submissionId = $submission->getId();
        $publication = $submission->getCurrentPublication();

        if ($result['success'] && !empty($result['fullSri'])) {
            $fullSri = $result['fullSri'];
            if ($publication && $this->getPubId($publication) !== $fullSri) {
                try {
                    $this->setStoredPubId($publication, $fullSri);
                } catch (\Throwable $e) {
                    error_log('[SRI-Plugin] Could not store SRI on publication: ' . $e->getMessage());
                }
            }
            $this->writeStatus($contextId, $submissionId, [
                'state' => $result['state'],
                'fullSri' => $fullSri,
                'suffixUsed' => $result['suffixUsed'] ?? ($article->manualSuffix !== '' ? $article->manualSuffix : ($result['suffixUsed'] ?? '')),
                'attempts' => $result['attempts'] ?? 1,
            ]);
            return;
        }

        // Failure: store the friendly reason for the OJS badge.
        $reason = $result['reason'] ?? (new StatusResolver())->resolveTransportError($result['message'] ?? '');
        $this->writeStatus($contextId, $submissionId, [
            'state' => StatusResolver::STATE_FAILED,
            'fullSri' => '',
            'reasonKey' => $reason['key'],
            'reasonParams' => $reason['params'],
            'message' => (string)($result['message'] ?? ''),
            'code' => (string)($result['code'] ?? ''),
            'httpStatus' => (int)($result['httpStatus'] ?? 0),
        ]);
    }

    /**
     * Compute the suffix for an article based on settings and the metadata
     * builder's prepared ArticleData.
     */
    public function computeSuffix(int $contextId, Submission $submission, ArticleData $article): string
    {
        $generator = new SuffixGenerator();
        $mode = (string)$this->setting($contextId, 'sriSuffix', SuffixGenerator::MODE_DEFAULT);
        $pattern = $mode === SuffixGenerator::MODE_PATTERN
            ? (string)$this->setting($contextId, 'sriPublicationSuffixPattern', SuffixGenerator::DEFAULT_PATTERN)
            : SuffixGenerator::DEFAULT_PATTERN;

        $suffix = $generator->generate($article, $mode, $pattern);

        // Sanity: never ship an invalid suffix to the API.
        if (!$generator->isValid($suffix)) {
            $suffix = $generator->sanitize($suffix, $article->articleId);
        }
        return $suffix;
    }

    //
    // Hooks ------------------------------------------------------------------------
    //

    /**
     * Hook: Publication::publish — automatic SRI registration at publish time.
     *
     * @param string $hookName 'Publication::publish'
     * @param array  $args     [&$newPublication, $publication, $submission]
     *
     * @return bool Best-effort: always returns Hook::CONTINUE so publishing
     *              is never blocked by SRI registration.
     */
    public function onPublicationPublish(string $hookName, array $args): bool
    {
        try {
            $newPublication = $args[0] ?? null;
            $submission = $args[2] ?? null;
            if (!$submission && $newPublication && method_exists($newPublication, 'getData')) {
                $submission = Repo::submission()->get($newPublication->getData('submissionId'));
            }
            if (!$submission) {
                return Hook::CONTINUE;
            }

            $contextId = (int)$submission->getData('contextId');
            if (!$this->isObjectTypeEnabled('Publication', $contextId)) {
                return Hook::CONTINUE;
            }
            if (!$this->setting($contextId, 'sriAutoRegister', true)) {
                return Hook::CONTINUE;
            }

            // Skip if this publication already carries an SRI.
            if ($newPublication && $this->getPubId($newPublication)) {
                return Hook::CONTINUE;
            }

            $this->registerSubmission($contextId, $submission->getId(), true);
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] onPublicationPublish error: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * Hook: ArticleHandler::view — emit citation_* meta tags so search engines
     * and indexers discover the SRI exactly like citation_doi today.
     *
     * @param string $hookName 'ArticleHandler::view'
     * @param array  $args     Scan for the Submission among args (signature varies by version).
     *
     * @return bool
     */
    public function onArticleView(string $hookName, array $args): bool
    {
        try {
            $submission = null;
            foreach ($args as $arg) {
                if ($arg instanceof Submission) {
                    $submission = $arg;
                    break;
                }
            }
            if (!$submission) {
                return Hook::CONTINUE;
            }
            $contextId = (int)$submission->getData('contextId');
            $publication = $submission->getCurrentPublication();
            $fullSri = $publication ? $this->getPubId($publication) : null;
            if (!$fullSri || !CheckCharacter::isValid((string)$fullSri)) {
                return Hook::CONTINUE;
            }

            $templateMgr = PKPTemplateManager::getManager(Application::get()->getRequest());
            $resolvingUrl = $this->getResolvingURL($contextId, (string)$fullSri);
            $templateMgr->addHeader(
                'sriCitationMeta',
                '<meta name="citation_sri" content="' . htmlspecialchars((string)$fullSri, ENT_QUOTES, 'UTF-8') . '">'
                . '<meta name="citation_sri_doi_link" content="' . htmlspecialchars($resolvingUrl, ENT_QUOTES, 'UTF-8') . '">'
            );
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] onArticleView error: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * Hook: Form::config::before — add a live status card to the publication
     * identifiers form (OJS 3.4+ backend editors).
     *
     * Unlike Publication::publish and ArticleHandler::view below (both fired
     * via the legacy Hook::call(), which passes $args as a single array),
     * OJS core's FormComponent fires this specific hook via the modern
     * Hook::run() convention — `Hook::run('Form::config::before', [$this])`
     * — which unpacks the args array into positional parameters. The 2nd
     * parameter here is therefore the FormComponent itself, never an array.
     * Declaring it as `array $args` throws a TypeError the instant OJS tries
     * to invoke this callback, on every Vue-based form OJS renders anywhere
     * (verified directly against pkp-lib's Hook.php and FormComponent.php)
     * — this is what crashed the whole admin/settings area, not just this
     * plugin's own settings screen.
     *
     * @param string                               $hookName 'Form::config::before'
     * @param \PKP\components\forms\FormComponent  $form
     *
     * @return bool
     */
    public function addPublicationFormFields(string $hookName, $form): bool
    {
        try {
            if (!$form) {
                return Hook::CONTINUE;
            }
            $formId = property_exists($form, 'id') ? $form->id : null;
            if ($formId !== 'publicationIdentifiers') {
                return Hook::CONTINUE;
            }
            $submissionContextId = property_exists($form, 'submissionContext') && $form->submissionContext
                ? (int)$form->submissionContext->getId()
                : 0;
            if ($submissionContextId === 0) {
                return Hook::CONTINUE;
            }
            if (!$this->isObjectTypeEnabled('Publication', $submissionContextId)) {
                return Hook::CONTINUE;
            }

            $submissionId = $form->publication ? $form->publication->getData('submissionId') : null;
            if (!$submissionId) {
                return Hook::CONTINUE;
            }

            $html = $this->renderStatusBlock($submissionContextId, (int)$submissionId);
            $form->addField(new FieldHTML('sriStatusCard', [
                'label' => __('plugins.pubIds.sri.displayName'),
                'description' => $html,
                'groupId' => 'default',
            ]));
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] addPublicationFormFields error: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    //
    // Management verbs --------------------------------------------------------------
    //

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $context = $request->getContext();
        $contextId = $context ? (int)$context->getId() : 0;
        $verb = (string)$request->getUserVar('verb');

        $csrfOk = $request->checkCSRF();

        switch ($verb) {
            case 'registerNow':
                return $this->objectAction($request, $csrfOk, fn () => $this->actionRegisterNow($request, $contextId));
            case 'refreshStatus':
                return $this->objectAction($request, $csrfOk, fn () => $this->actionRefreshStatus($request, $contextId));
            case 'attachExisting':
                return $this->objectAction($request, $csrfOk, fn () => $this->actionAttachExisting($request, $contextId));
            case 'bulk':
                return $this->csrfAction($csrfOk, fn () => $this->actionBulkScreen($request, $contextId));
            case 'bulkRun':
                return $this->csrfAction($csrfOk, fn () => $this->actionBulkRun($request, $contextId));
            default:
                return parent::manage($args, $request);
        }
    }

    private function csrfAction(bool $csrfOk, \Closure $action): JSONMessage
    {
        if (!$csrfOk) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.csrf'));
        }
        try {
            return $action();
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] management action error: ' . $e->getMessage());
            return new JSONMessage(false, $e->getMessage());
        }
    }

    /**
     * Wraps the three per-submission actions (registerNow / refreshStatus /
     * attachExisting). Those controls are rendered in two different
     * contexts: bound via jQuery in classic Smarty areas, and embedded as
     * raw HTML inside the Vue-based publication identifiers form (via
     * FieldHTML's description — see addPublicationFormFields). Vue's
     * v-html — like any plain innerHTML assignment — never executes
     * injected <script> tags; that is standard browser behavior, not an
     * OJS quirk. So in the second context, no jQuery handler ever binds to
     * these links/forms, and clicking one is a PLAIN navigation, not an
     * XHR call — which would otherwise dump a raw JSONMessage to the
     * screen instead of updating anything.
     *
     * jQuery always sends `X-Requested-With: XMLHttpRequest` on a real AJAX
     * call, so that header reliably distinguishes the two cases: real AJAX
     * callers keep getting the JSONMessage unchanged; a plain navigation
     * performs the action, raises a flash notification (the same
     * createTrivialNotification pattern OJS's own PKPPubIdPlugin::manage()
     * uses for a settings save), and redirects back to the submission's
     * workflow page, where a fresh page load re-renders the status card
     * with the updated state.
     */
    private function objectAction($request, bool $csrfOk, \Closure $action)
    {
        $ajax = $this->isAjaxRequest();
        $submissionId = (int)$request->getUserVar('submissionId');

        if (!$csrfOk) {
            if ($ajax) {
                return new JSONMessage(false, __('plugins.pubIds.sri.error.csrf'));
            }
            $this->redirectAfterAction($request, $submissionId);
            return;
        }

        try {
            $result = $action();
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] management action error: ' . $e->getMessage());
            $result = new JSONMessage(false, $e->getMessage());
        }

        if ($ajax) {
            return $result;
        }

        $user = $request->getUser();
        if ($user) {
            (new NotificationManager())->createTrivialNotification(
                $user->getId(),
                $result->getStatus() ? Notification::NOTIFICATION_TYPE_SUCCESS : Notification::NOTIFICATION_TYPE_ERROR
            );
        }
        $this->redirectAfterAction($request, $submissionId);
    }

    private function redirectAfterAction($request, int $submissionId): void
    {
        if ($submissionId > 0) {
            $request->redirect(null, 'workflow', 'access', [$submissionId]);
            return;
        }
        $request->redirect(null, 'index');
    }

    /**
     * jQuery sets this header on every AJAX call; a plain browser
     * navigation (link click, form submit with no bound handler) never
     * does. This is the standard, framework-agnostic way to distinguish
     * the two — not an OJS-specific mechanism.
     */
    private function isAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function actionRegisterNow($request, int $contextId): JSONMessage
    {
        $submissionId = (int)$request->getUserVar('submissionId');
        if ($submissionId <= 0) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.missingSubmission'));
        }
        $result = $this->registerSubmission($contextId, $submissionId, true);
        if ($result['success']) {
            return new JSONMessage(true, __(
                'plugins.pubIds.sri.action.registered',
                ['fullSri' => $result['fullSri'], 'state' => $result['state']]
            ));
        }
        return new JSONMessage(true, $this->renderStatusBlock($contextId, $submissionId));
    }

    private function actionRefreshStatus($request, int $contextId): JSONMessage
    {
        $submissionId = (int)$request->getUserVar('submissionId');
        $submission = $submissionId > 0 ? Repo::submission()->get($submissionId) : null;
        if (!$submission) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.missingSubmission'));
        }
        $publication = $submission->getCurrentPublication();
        $fullSri = $publication ? $this->getPubId($publication) : null;
        if (!$fullSri) {
            return new JSONMessage(true, $this->renderStatusBlock($contextId, $submissionId));
        }
        $service = $this->getService($contextId);
        $status = $service->checkStatus((string)$fullSri);
        $this->writeStatus($contextId, $submissionId, [
            'state' => $status['success'] ? $status['state'] : StatusResolver::STATE_FAILED,
            'fullSri' => (string)$fullSri,
            'reasonKey' => isset($status['reason']) ? $status['reason']['key'] : null,
            'reasonParams' => isset($status['reason']) ? $status['reason']['params'] : [],
            'message' => $status['message'] ?? '',
        ]);
        return new JSONMessage(true, $this->renderStatusBlock($contextId, $submissionId));
    }

    private function actionAttachExisting($request, int $contextId): JSONMessage
    {
        $submissionId = (int)$request->getUserVar('submissionId');
        $fullSri = trim((string)$request->getUserVar('fullSri'));
        if ($submissionId <= 0) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.missingSubmission'));
        }
        if (!CheckCharacter::isValid($fullSri)) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.invalidSri'));
        }
        $submission = Repo::submission()->get($submissionId);
        $publication = $submission ? $submission->getCurrentPublication() : null;
        if (!$publication) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.missingSubmission'));
        }
        $this->setStoredPubId($publication, $fullSri);
        $this->writeStatus($contextId, $submissionId, [
            'state' => StatusResolver::STATE_ACTIVE,
            'fullSri' => $fullSri,
        ]);
        return new JSONMessage(true, __('plugins.pubIds.sri.action.attached', ['fullSri' => $fullSri]));
    }

    private function actionBulkScreen($request, int $contextId): JSONMessage
    {
        $issues = $this->issueOptions($contextId);

        $templateMgr = PKPTemplateManager::getManager($request);
        $templateMgr->assign([
            'sriIssues' => $issues,
            'sriActionUrl' => $this->manageUrl($request, 'bulkRun'),
        ]);
        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('bulkForm.tpl')));
    }

    private function actionBulkRun($request, int $contextId): JSONMessage
    {
        $issueId = (int)$request->getUserVar('issueId');
        $submissionIds = $this->submissionIdsForIssue($contextId, $issueId);

        if (empty($submissionIds)) {
            return new JSONMessage(true, __('plugins.pubIds.sri.bulk.none'));
        }

        $mapper = new MetadataMapper();
        $builder = $this->metadataBuilder();
        $prefix = (int)$this->setting($contextId, 'sriPrefix', 0);
        $rows = [];
        $skipped = 0;
        foreach ($submissionIds as $submissionId) {
            $submission = Repo::submission()->get($submissionId);
            if (!$submission) {
                continue;
            }
            $publication = $submission->getCurrentPublication();
            if (!$publication || $this->getPubId($publication)) {
                $skipped++;
                continue;
            }
            $article = $builder->fromSubmission($submission);
            $suffix = $this->computeSuffix($contextId, $submission, $article);
            $rows[] = $mapper->toBulkRow($article, $prefix, $suffix);
            // Mark local status as in-flight so the screen reflects progress.
            $this->writeStatus($contextId, $submission->getId(), ['state' => 'queued', 'fullSri' => '']);
        }

        $service = $this->getService($contextId);
        $result = $service->submitBulk($rows);

        if (!$result['success']) {
            return new JSONMessage(false, $result['message'] ?? __('plugins.pubIds.sri.bulk.failed'));
        }

        return new JSONMessage(true, __(
            'plugins.pubIds.sri.bulk.submitted',
            ['jobId' => $result['jobId'], 'count' => (string)$result['totalRows'], 'skipped' => (string)$skipped]
        ));
    }

    //
    // Rendering helpers --------------------------------------------------------------
    //

    /**
     * Render the per-submission status card (used in the identifiers tab and
     * the publication identifiers form).
     */
    public function renderStatusBlock(int $contextId, int $submissionId): string
    {
        $submission = Repo::submission()->get($submissionId);
        $publication = $submission ? $submission->getCurrentPublication() : null;
        $storedSri = $publication ? $this->getPubId($publication) : null;
        $status = $this->readStatus($contextId, $submissionId);

        $presenter = new StatePresenter();
        $presentation = $presenter->present($storedSri, $status);

        $request = Application::get()->getRequest();
        $session = $request->getSession();
        $csrf = $session && method_exists($session, 'getCSRFToken') ? $session->getCSRFToken() : null;

        $templateMgr = PKPTemplateManager::getManager($request);
        $templateMgr->assign([
            'sriState' => $presentation['state'],
            'sriStateLabel' => __($presentation['labelKey']),
            'sriFullSri' => $presentation['fullSri'],
            'sriResolvingUrl' => $presentation['fullSri'] !== '' ? $this->getResolvingURL($contextId, $presentation['fullSri']) : '',
            'sriReason' => $presentation['reason'] !== '' ? __($presentation['reason']) : '',
            'sriUpdatedAt' => $status['updatedAt'] ?? '',
            'sriSubmissionId' => $submissionId,
            'sriActionSuffix' => $this->computeSuffixForStatus($contextId, $submissionId),
            'sriActionRegisterUrl' => $request->url(null, null, 'manage', null, ['verb' => 'registerNow', 'category' => 'pubIds', 'plugin' => $this->getName(), 'submissionId' => $submissionId, 'csrfToken' => $csrf]),
            'sriActionRefreshUrl' => $request->url(null, null, 'manage', null, ['verb' => 'refreshStatus', 'category' => 'pubIds', 'plugin' => $this->getName(), 'submissionId' => $submissionId, 'csrfToken' => $csrf]),
            'sriActionAttachUrl' => $request->url(null, null, 'manage', null, ['verb' => 'attachExisting', 'category' => 'pubIds', 'plugin' => $this->getName(), 'submissionId' => $submissionId, 'csrfToken' => $csrf]),
        ]);

        return $templateMgr->fetch($this->getTemplateResource('statusCard.tpl'));
    }

    /**
     * @return array{state: string, labelKey: string, fullSri: string, reason: string}
     */
    public function presentStateFor(int $contextId, int $submissionId): array
    {
        $submission = Repo::submission()->get($submissionId);
        $publication = $submission ? $submission->getCurrentPublication() : null;
        $storedSri = $publication ? $this->getPubId($publication) : null;
        $status = $this->readStatus($contextId, $submissionId);

        $presenter = new StatePresenter();
        return $presenter->present($storedSri, $status);
    }

    private function computeSuffixForStatus(int $contextId, int $submissionId): string
    {
        try {
            $submission = Repo::submission()->get($submissionId);
            $article = $submission ? $this->metadataBuilder()->fromSubmission($submission) : null;
            return $article ? $this->computeSuffix($contextId, $submission, $article) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Preview the suffix that would be used for a pub object (identifierStatus.tpl).
     */
    public function getSuffixValue($pubObject, string $pubObjectType = 'Publication'): string
    {
        try {
            $submissionId = $pubObject instanceof Submission
                ? $pubObject->getId()
                : (method_exists($pubObject, 'getData') ? $pubObject->getData('submissionId') : null);
            if (!$submissionId) {
                return '';
            }
            $contextId = $pubObject instanceof Submission
                ? (int)$pubObject->getData('contextId')
                : (method_exists($pubObject, 'getData') ? (int)$pubObject->getData('contextId') : 0);
            $submission = Repo::submission()->get((int)$submissionId);
            if (!$submission) {
                return '';
            }
            $article = $this->metadataBuilder()->fromSubmission($submission);
            return $this->computeSuffix($contextId, $submission, $article);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function manageUrl($request, string $verb): string
    {
        return $request->url(null, null, 'manage', null, [
            'verb' => $verb,
            'category' => 'pubIds',
            'plugin' => $this->getName(),
        ]);
    }

    private function metadataBuilder(): SriMetadataBuilder
    {
        return new SriMetadataBuilder($this);
    }

    private function issueOptions(int $contextId): array
    {
        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByPublished(true)
            ->orderBy(Repo::issue()::ORDERBY_SEQUENCE)
            ->getMany();

        $options = [];
        foreach ($issues as $issue) {
            $label = trim(
                (string)$issue->getData('volume')
                . ($issue->getData('number') ? '(' . (string)$issue->getData('number') . ')' : '')
                . ($issue->getData('year') ? ' — ' . (string)$issue->getData('year') : '')
            );
            $options[$issue->getId()] = $label !== '' ? $label : (string)$issue->getId();
        }
        return $options;
    }

    /**
     * @return int[] Published submission ids in an issue.
     */
    private function submissionIdsForIssue(int $contextId, int $issueId): array
    {
        $collector = Repo::submission()->getCollector();
        if (method_exists($collector, 'filterByContextIds')) {
            $collector->filterByContextIds([$contextId]);
        }
        // Published submissions assigned to this issue via their current publication.
        $submissions = $collector->filterByStatus(Submission::STATUS_PUBLISHED)->getMany();
        $ids = [];
        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            if ($publication && (int)$publication->getData('issueId') === $issueId) {
                $ids[] = (int)$submission->getId();
            }
        }
        return $ids;
    }
}
