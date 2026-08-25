<?php

/**
 * @file plugins/pubIds/sri/SriPubIdPlugin.inc.php
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
 * This is the OJS 3.3 legacy adapter (non-namespaced, extends the legacy
 * PubIdPlugin). It shares the exact same version-independent core
 * (classes/core/, namespace SRI\Plugin) as the 3.4/3.5 adapter.
 */

import('classes.plugins.PubIdPlugin');

use SRI\Plugin\ApiClient;
use SRI\Plugin\ArticleData;
use SRI\Plugin\CheckCharacter;
use SRI\Plugin\MetadataMapper;
use SRI\Plugin\RegistrationService;
use SRI\Plugin\StatePresenter;
use SRI\Plugin\StatusResolver;
use SRI\Plugin\SuffixGenerator;

class SriPubIdPlugin extends PubIdPlugin
{
    /** @var string OJS-visible identifier/class type (must match folder/class rules). */
    public const PUB_ID_TYPE = 'sri';

    /** @var string Public display type. The project/plugin name is SRI-Plugin. */
    public const DISPLAY_TYPE = 'SRI-Plugin';

    public const VERSION = '1.2.0';

    /** @var bool Core bootstrapped flag. */
    private $_coreBootstrapped = false;

    //
    // Plugin identity -----------------------------------------------------------
    //

    public function getDisplayName()
    {
        return __('plugins.pubIds.sri.displayName');
    }

    public function getDescription()
    {
        return __('plugins.pubIds.sri.description');
    }

    //
    // Registration --------------------------------------------------------------
    //

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }
        if (defined('RUNNING_UPGRADE') || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        $this->bootstrapCore();

        HookRegistry::register('Publication::publish', [$this, 'onPublicationPublish']);
        HookRegistry::register('ArticleHandler::view', [$this, 'onArticleView']);
        HookRegistry::register('Form::config::before', [$this, 'addPublicationFormFields']);

        return $success;
    }

    private function bootstrapCore()
    {
        if ($this->_coreBootstrapped) {
            return;
        }
        $bootstrap = dirname(__FILE__) . '/classes/bootstrap.php';
        if (is_file($bootstrap)) {
            require_once($bootstrap);
        } elseif (!class_exists(ApiClient::class)) {
            error_log('[SRI-Plugin] Shared core missing under classes/core/ — run php build/package.php. SRI registration disabled.');
        }
        $this->_coreBootstrapped = true;
    }

    //
    // Abstract PKPPubIdPlugin members ------------------------------------------
    //

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

    public function constructPubId($pubIdPrefix, $pubIdSuffix, $contextId)
    {
        $year = (int)date('Y');
        $prefix = (int)preg_replace('/\D+/', '', (string)$pubIdPrefix);
        if ($prefix <= 0) {
            return '';
        }
        return CheckCharacter::buildSri($year, $prefix, (string)$pubIdSuffix);
    }

    public function getPubIdType()
    {
        return self::PUB_ID_TYPE;
    }

    public function getPubIdDisplayType()
    {
        return self::DISPLAY_TYPE;
    }

    public function getPubIdFullName()
    {
        return __('plugins.pubIds.sri.fullName');
    }

    public function getResolvingURL($contextId, $pubId)
    {
        $origin = $this->getResolverOrigin($contextId);
        $pubId = (string)$pubId;
        $path = strpos($pubId, 'sri:') === 0 ? substr($pubId, 4) : $pubId;
        return rtrim($origin, '/') . '/sri/' . $path;
    }

    public function getPubIdMetadataFile()
    {
        return $this->getTemplateResource('identifierStatus.tpl');
    }

    public function addJavaScript($request, $templateMgr)
    {
        // No JS needed: actions use core LinkAction handlers.
    }

    public function getPubIdAssignFile()
    {
        return $this->getTemplateResource('identifierStatus.tpl');
    }

    public function instantiateSettingsForm($contextId)
    {
        $this->import('form.SriSettingsForm');
        return new SriSettingsForm($this, $contextId);
    }

    public function getFormFieldNames()
    {
        return ['sriSuffix'];
    }

    public function getAssignFormFieldName()
    {
        return 'assignSri';
    }

    public function getPrefixFieldName()
    {
        return 'sriPrefix';
    }

    public function getSuffixFieldName()
    {
        return 'sriSuffix';
    }

    public function getSuffixPatternsFieldNames()
    {
        return [
            'Issue' => 'sriIssueSuffixPattern',
            'Publication' => 'sriPublicationSuffixPattern',
            'Representation' => 'sriRepresentationSuffixPattern',
        ];
    }

    public function getDAOFieldNames()
    {
        return ['pub-id::' . self::PUB_ID_TYPE];
    }

    public function getPubObjectTypes()
    {
        return [
            'Issue' => 'Issue',
            'Publication' => '\APP\publication\Publication',
            'Representation' => '\PKP\submission\Representation',
            'SubmissionFile' => 'SubmissionFile',
        ];
    }

    public function isObjectTypeEnabled($pubObjectType, $contextId)
    {
        if ($pubObjectType === 'Publication') {
            return (bool)$this->setting($contextId, 'enablePublicationSri', true);
        }
        if ($pubObjectType === 'Representation') {
            return (bool)$this->setting($contextId, 'enableRepresentationSri', false);
        }
        if ($pubObjectType === 'Issue') {
            return (bool)$this->setting($contextId, 'enableIssueSri', false);
        }
        if ($pubObjectType === 'SubmissionFile') {
            return (bool)$this->setting($contextId, 'enableSubmissionFileSri', false);
        }
        return false;
    }

    public function getNotUniqueErrorMsg()
    {
        return __('plugins.pubIds.sri.editor.suffixNotUnique');
    }

    public function getLinkActions($pubObject)
    {
        $linkActions = [];
        $request = Application::getRequest();
        $userVars = $request->getUserVars();
        $userVars['pubIdPlugIn'] = get_class($this);

        $objectId = method_exists($pubObject, 'getId') ? $pubObject->getId() : null;
        $submissionId = null;
        if ($pubObject instanceof \APP\submission\Submission) {
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
                $this->componentUrl($request, 'clearPubId', $actionArgs),
                'modal_delete'
            ),
            __('plugins.pubIds.sri.editor.clearObjectsSri'),
            'delete'
        );

        return $linkActions;
    }

    public function getClearAllLinkAction($contextId)
    {
        $request = Application::getRequest();
        return new LinkAction(
            'clearAllSri',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.pubIds.sri.manager.settings.clearAll.confirm'),
                __('common.delete'),
                $this->componentUrl($request, 'clearPubIds'),
                'modal_delete'
            ),
            __('plugins.pubIds.sri.manager.settings.clearAll'),
            'delete'
        );
    }

    public function getBulkLinkAction($contextId)
    {
        $request = Application::getRequest();
        return new LinkAction(
            'sriBulk',
            new AjaxModal(
                $this->componentUrl($request, 'bulk'),
                __('plugins.pubIds.sri.bulk.title')
            ),
            __('plugins.pubIds.sri.bulk.title')
        );
    }

    //
    // Settings / services --------------------------------------------------------
    //

    
    /**
     * Build a URL for this plugin's component-router manage action.
     */
    private function componentUrl($request, $verb, array $extraParams = array())
    {
        $dispatcher = $request->getDispatcher();
        return $dispatcher->url(
            $request,
            ROUTE_COMPONENT,
            null,
            'grid.settings.plugins.SettingsPluginGridHandler',
            'manage',
            null,
            array_merge($extraParams, array(
                'verb' => $verb,
                'category' => 'pubIds',
                'plugin' => $this->getName(),
            ))
        );
    }

    public function getAccountStatusUrl()
    {
        $request = Application::getRequest();
        $session = $request->getSession();
        $csrf = $session && method_exists($session, 'getCSRFToken') ? $session->getCSRFToken() : null;
        return $this->componentUrl($request, 'accountStatus', array('csrfToken' => $csrf));
    }

    public function setting($contextId, $name, $default = null)
    {
        $value = $this->getSetting($contextId, $name);
        return $value === null || $value === '' ? $default : $value;
    }

    public function getApiClient($contextId)
    {
        $baseUrl = (string)$this->setting($contextId, 'sriBaseUrl', '');
        $apiKey = (string)$this->setting($contextId, 'sriApiKey', '');
        $connectTimeout = max(1, (int)$this->setting($contextId, 'sriConnectTimeout', 10));
        $timeout = max(1, (int)$this->setting($contextId, 'sriTimeout', 30));
        $version = defined('APP_VERSION') ? (string)APP_VERSION : '';
        $ua = 'SRI-Plugin/' . self::VERSION . ' (+OJS ' . $version . '; SRI-Plugin; +https://scitekhub.com)';
        return new ApiClient($baseUrl, $apiKey, $connectTimeout, $timeout, null, $ua);
    }

    public function getService($contextId)
    {
        return new RegistrationService($this->getApiClient($contextId));
    }

    public function getResolverOrigin($contextId)
    {
        $resolver = trim((string)$this->setting($contextId, 'sriResolverUrl', ''));
        if ($resolver === '') {
            $resolver = (string)$this->setting($contextId, 'sriBaseUrl', '');
            $resolver = (string)preg_replace('#/api/v1/?$#', '', $resolver);
        }
        return rtrim($resolver, '/');
    }

    //
    // Status persistence ----------------------------------------------------------
    //

    public function writeStatus($contextId, $submissionId, array $status)
    {
        $status['updatedAt'] = date('c');
        $this->updateSetting($contextId, $this->statusKey($submissionId), (string)json_encode($status), 'string');
    }

    public function readStatus($contextId, $submissionId)
    {
        $raw = $this->getSetting($contextId, $this->statusKey($submissionId));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function clearStatus($contextId, $submissionId)
    {
        $this->updateSetting($contextId, $this->statusKey($submissionId), '', 'string');
    }

    private function statusKey($submissionId)
    {
        return 'sriStatus:' . $submissionId;
    }

    //
    // Core registration flow (shared with 3.4 adapter) -----------------------------
    //

    public function registerSubmission($contextId, $submissionId, $retryOnConflict = true)
    {
        try {
            if (!$this->isObjectTypeEnabled('Publication', $contextId)) {
                throw new \Exception('Publication-level SRI registration is disabled');
            }

            $submission = $this->getSubmission($submissionId);
            if (!$submission) {
                throw new \Exception('Submission not found');
            }
            if ((int)$submission->getContextId() !== (int)$contextId) {
                throw new \Exception('Submission belongs to another context');
            }

            $article = $this->metadataBuilder()->fromSubmission($submission);
            $suffix = $this->computeSuffix($contextId, $submission, $article);
            $prefix = (int)$this->setting($contextId, 'sriPrefix', 0);
            if ($prefix <= 0) {
                throw new \Exception('SRI prefix is not configured');
            }

            $service = $this->getService($contextId);
            $result = $retryOnConflict
                ? $service->registerWithRetry($article, $prefix, $suffix)
                : $service->register($article, $prefix, $suffix);

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

    private function persistResult($contextId, $submission, array $result, ArticleData $article)
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
                'suffixUsed' => $result['suffixUsed'] ?? '',
                'attempts' => $result['attempts'] ?? 1,
            ]);
            return;
        }

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

    public function computeSuffix($contextId, $submission, ArticleData $article)
    {
        $generator = new SuffixGenerator();
        $mode = (string)$this->setting($contextId, 'sriSuffix', SuffixGenerator::MODE_DEFAULT);
        $pattern = $mode === SuffixGenerator::MODE_PATTERN
            ? (string)$this->setting($contextId, 'sriPublicationSuffixPattern', SuffixGenerator::DEFAULT_PATTERN)
            : SuffixGenerator::DEFAULT_PATTERN;

        $suffix = $generator->generate($article, $mode, $pattern);
        if (!$generator->isValid($suffix)) {
            $suffix = $generator->sanitize($suffix, $article->articleId);
        }
        return $suffix;
    }

    //
    // Hooks -----------------------------------------------------------------------
    //

    public function onPublicationPublish($hookName, $args)
    {
        try {
            $newPublication = $args[0] ?? null;
            $submission = null;
            foreach ($args as $arg) {
                if ($arg instanceof \APP\submission\Submission) {
                    $submission = $arg;
                    break;
                }
            }
            if (!$submission && $newPublication && method_exists($newPublication, 'getData')) {
                $submission = $this->getSubmission($newPublication->getData('submissionId'));
            }
            if (!$submission) {
                return false;
            }

            $contextId = (int)$submission->getContextId();
            if (!$this->isObjectTypeEnabled('Publication', $contextId)) {
                return false;
            }
            if (!$this->setting($contextId, 'sriAutoRegister', true)) {
                return false;
            }
            if ($newPublication && $this->getPubId($newPublication)) {
                return false;
            }

            $this->registerSubmission($contextId, $submission->getId(), true);
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] onPublicationPublish error: ' . $e->getMessage());
        }
        return false;
    }

    public function onArticleView($hookName, $args)
    {
        try {
            $submission = null;
            foreach ($args as $arg) {
                if ($arg instanceof \APP\submission\Submission) {
                    $submission = $arg;
                    break;
                }
            }
            if (!$submission) {
                return false;
            }
            $contextId = (int)$submission->getContextId();
            $publication = $submission->getCurrentPublication();
            $fullSri = $publication ? $this->getPubId($publication) : null;
            if (!$fullSri || !CheckCharacter::isValid((string)$fullSri)) {
                return false;
            }

            $templateMgr = TemplateManager::getManager(Application::getRequest());
            $resolvingUrl = $this->getResolvingURL($contextId, (string)$fullSri);
            $templateMgr->addHeader(
                'sriCitationMeta',
                '<meta name="citation_sri" content="' . htmlspecialchars((string)$fullSri, ENT_QUOTES, 'UTF-8') . '">'
                . '<meta name="citation_sri_doi_link" content="' . htmlspecialchars($resolvingUrl, ENT_QUOTES, 'UTF-8') . '">'
            );
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] onArticleView error: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Hook: Form::config::before.
     *
     * NOTE: current pkp-lib fires this via the modern Hook::run() convention
     * (`Hook::run('Form::config::before', [$this])`), which unpacks the args
     * array into positional parameters — so $form here is the FormComponent
     * itself, never an array. Declaring the 2nd parameter as an array threw a
     * TypeError on every Vue-based form OJS rendered (this crashed the whole
     * admin/settings area, not just this plugin's settings screen) — see the
     * identical fix and full explanation in plugin34/SriPubIdPlugin.php. The
     * Vue FormComponent system does not exist on stock OJS 3.3, so this hook
     * is not expected to fire there; fixed for correctness and consistency
     * with the 3.4/3.5 adapter regardless.
     */
    public function addPublicationFormFields($hookName, $form)
    {
        try {
            if (!$form) {
                return false;
            }
            $formId = property_exists($form, 'id') ? $form->id : null;
            if ($formId !== 'publicationIdentifiers') {
                return false;
            }
            $submissionContextId = property_exists($form, 'submissionContext') && $form->submissionContext
                ? (int)$form->submissionContext->getId()
                : 0;
            if ($submissionContextId === 0) {
                return false;
            }
            if (!$this->isObjectTypeEnabled('Publication', $submissionContextId)) {
                return false;
            }

            $submissionId = $form->publication ? $form->publication->getData('submissionId') : null;
            if (!$submissionId) {
                return false;
            }

            $html = $this->renderStatusBlock($submissionContextId, (int)$submissionId);
            $form->addField(new \PKP\components\forms\FieldHTML('sriStatusCard', [
                'label' => __('plugins.pubIds.sri.displayName'),
                'description' => $html,
                'groupId' => 'default',
            ]));
        } catch (\Throwable $e) {
            error_log('[SRI-Plugin] addPublicationFormFields error: ' . $e->getMessage());
        }
        return false;
    }

    //
    // Management verbs --------------------------------------------------------------
    //

    public function manage($args, $request)
    {
        $context = $request->getContext();
        $contextId = $context ? (int)$context->getId() : 0;
        $verb = (string)$request->getUserVar('verb');

        $csrfOk = $request->checkCSRF();

        switch ($verb) {
            case 'registerNow':
                return $this->objectAction($request, $csrfOk, function () use ($request, $contextId) {
                    return $this->actionRegisterNow($request, $contextId);
                });
            case 'refreshStatus':
                return $this->objectAction($request, $csrfOk, function () use ($request, $contextId) {
                    return $this->actionRefreshStatus($request, $contextId);
                });
            case 'attachExisting':
                return $this->objectAction($request, $csrfOk, function () use ($request, $contextId) {
                    return $this->actionAttachExisting($request, $contextId);
                });
            case 'bulk':
                return $this->actionBulkScreen($request, $contextId);
            case 'accountStatus':
                return $this->actionAccountStatus($request, $contextId);
            case 'bulkRun':
                return $this->csrfAction($csrfOk, function () use ($request, $contextId) {
                    return $this->actionBulkRun($request, $contextId);
                });
            default:
                return parent::manage($args, $request);
        }
    }

    private function csrfAction($csrfOk, $action)
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
     * attachExisting). Not currently reachable through any 3.3 UI element
     * (the Vue publication identifiers form these controls are embedded
     * into via addPublicationFormFields doesn't exist on 3.3, and the
     * legacy identifierStatus.tpl doesn't surface them either) -- kept
     * aligned with the 3.4/3.5 adapter for consistency and in case a
     * future template change surfaces them here too. See the 3.4/3.5
     * adapter's objectAction() for the full reasoning: jQuery handlers
     * bound via a <script> tag inside HTML injected through Vue's v-html
     * never execute (standard browser/innerHTML behavior), so a plain
     * navigation must be redirected instead of returned as a bare
     * JSONMessage.
     */
    private function objectAction($request, $csrfOk, $action)
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
            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification(
                $user->getId(),
                $result->getStatus() ? NOTIFICATION_TYPE_SUCCESS : NOTIFICATION_TYPE_ERROR
            );
        }
        $this->redirectAfterAction($request, $submissionId);
    }

    private function redirectAfterAction($request, $submissionId)
    {
        if ($submissionId > 0) {
            $request->redirect(null, 'workflow', 'access', [$submissionId]);
            return;
        }
        $request->redirect(null, 'index');
    }

    private function isAjaxRequest()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function actionRegisterNow($request, $contextId)
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

    private function actionRefreshStatus($request, $contextId)
    {
        $submissionId = (int)$request->getUserVar('submissionId');
        $submission = $submissionId > 0 ? $this->getSubmission($submissionId) : null;
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
            'message' => (string)($status['message'] ?? ''),
        ]);
        return new JSONMessage(true, $this->renderStatusBlock($contextId, $submissionId));
    }

    private function actionAttachExisting($request, $contextId)
    {
        $submissionId = (int)$request->getUserVar('submissionId');
        $fullSri = trim((string)$request->getUserVar('fullSri'));
        if ($submissionId <= 0) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.missingSubmission'));
        }
        if (!CheckCharacter::isValid($fullSri)) {
            return new JSONMessage(false, __('plugins.pubIds.sri.error.invalidSri'));
        }
        $submission = $this->getSubmission($submissionId);
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

    private function actionBulkScreen($request, $contextId)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'sriIssues' => $this->issueOptions($contextId),
            'sriActionUrl' => $this->manageUrl($request, 'bulkRun'),
        ]);
        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('bulkForm.tpl')));
    }

    private function actionBulkRun($request, $contextId)
    {
        if (!$this->isObjectTypeEnabled('Publication', $contextId)) {
            return new JSONMessage(false, __('plugins.pubIds.sri.manager.settings.sriPublicationDisabled'));
        }

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
            $submission = $this->getSubmission($submissionId);
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
    // Rendering helpers ---------------------------------------------------------------
    //

    public function renderStatusBlock($contextId, $submissionId)
    {
        $submission = $this->getSubmission($submissionId);
        $publication = $submission ? $submission->getCurrentPublication() : null;
        $storedSri = $publication ? $this->getPubId($publication) : null;
        $status = $this->readStatus($contextId, $submissionId);

        $presenter = new StatePresenter();
        $presentation = $presenter->present($storedSri, $status);

        $request = Application::getRequest();
        $session = $request->getSession();
        $csrf = $session && method_exists($session, 'getCSRFToken') ? $session->getCSRFToken() : null;

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'sriState' => $presentation['state'],
            'sriStateLabel' => __($presentation['labelKey']),
            'sriFullSri' => $presentation['fullSri'],
            'sriResolvingUrl' => $presentation['fullSri'] !== '' ? $this->getResolvingURL($contextId, $presentation['fullSri']) : '',
            'sriReason' => $presentation['reason'] !== '' ? __($presentation['reason'], $status['reasonParams'] ?? array()) : '',
            'sriUpdatedAt' => $status['updatedAt'] ?? '',
            'sriSubmissionId' => $submissionId,
            'sriActionSuffix' => $this->computeSuffixForStatus($contextId, $submissionId),
            'sriActionRegisterUrl' => $this->componentUrl($request, 'registerNow', array('submissionId' => $submissionId, 'csrfToken' => $csrf)),
            'sriActionRefreshUrl' => $this->componentUrl($request, 'refreshStatus', array('submissionId' => $submissionId, 'csrfToken' => $csrf)),
            'sriActionAttachUrl' => $this->componentUrl($request, 'attachExisting', array('submissionId' => $submissionId, 'csrfToken' => $csrf)),
        ]);

        return $templateMgr->fetch($this->getTemplateResource('statusCard.tpl'));
    }

    public function getSuffixValue($pubObject, $pubObjectType = 'Publication')
    {
        try {
            $submissionId = $pubObject instanceof \APP\submission\Submission
                ? $pubObject->getId()
                : (method_exists($pubObject, 'getData') ? $pubObject->getData('submissionId') : null);
            if (!$submissionId) {
                return '';
            }
            $contextId = $pubObject instanceof \APP\submission\Submission
                ? (int)$pubObject->getContextId()
                : (method_exists($pubObject, 'getData') ? (int)$pubObject->getData('contextId') : 0);
            $submission = $this->getSubmission((int)$submissionId);
            if (!$submission) {
                return '';
            }
            $article = $this->metadataBuilder()->fromSubmission($submission);
            return $this->computeSuffix($contextId, $submission, $article);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function computeSuffixForStatus($contextId, $submissionId)
    {
        try {
            $submission = $this->getSubmission($submissionId);
            $article = $submission ? $this->metadataBuilder()->fromSubmission($submission) : null;
            return $article ? $this->computeSuffix($contextId, $submission, $article) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function manageUrl($request, $verb)
    {
        return $this->componentUrl($request, $verb);
    }

    private function metadataBuilder()
    {
        $this->import('classes.SriMetadataBuilder');
        return new SriMetadataBuilder($this);
    }

    private function getSubmission($id)
    {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        return $submissionDao->getById((int)$id);
    }

    private function issueOptions($contextId)
    {
        $issueDao = DAORegistry::getDAO('IssueDAO');
        $options = [];
        foreach (iterator_to_array($issueDao->getByContextId($contextId, false)) as $issue) {
            $label = method_exists($issue, 'getIssueIdentification') ? $issue->getIssueIdentification() : '';
            if ($label === '') {
                $label = trim(
                    (string)$issue->getVolume()
                    . ($issue->getNumber() ? ' (' . (string)$issue->getNumber() . ')' : '')
                    . ($issue->getYear() ? ' — ' . (string)$issue->getYear() : '')
                );
            }
            $options[$issue->getId()] = $label !== '' ? $label : ('Issue #' . $issue->getId());
        }
        return $options;
    }

    public function getIssueArticlesData($issue)
    {
        $contextId = (int)$issue->getJournalId();
        $issueId = (int)$issue->getId();
        $submissionIds = $this->submissionIdsForIssue($contextId, $issueId);
        $articles = [];
        foreach ($submissionIds as $submissionId) {
            $submission = $this->getSubmission($submissionId);
            if (!$submission) {
                continue;
            }
            $publication = $submission->getCurrentPublication();
            $storedSri = $publication ? $this->getPubId($publication) : null;
            $status = $this->readStatus($contextId, $submissionId);
            $presenter = new StatePresenter();
            $presentation = $presenter->present($storedSri, $status);
            $title = $publication ? $publication->getLocalizedTitle() : $submission->getLocalizedTitle();
            $articles[] = [
                'submissionId' => $submissionId,
                'title' => $title ?: __('common.untitled'),
                'fullSri' => $presentation['fullSri'],
                'displaySri' => $presentation['displaySri'] ?? CheckCharacter::cleanSri($presentation['fullSri']),
                'resolvingUrl' => $presentation['fullSri'] !== '' ? $this->getResolvingURL($contextId, $presentation['fullSri']) : '',
                'state' => $presentation['state'],
                'stateLabel' => __($presentation['labelKey']),
            ];
        }
        return $articles;
    }

    public function clearIssueObjectsPubIds($issue)
    {
        $publicationPubIdEnabled = $this->isObjectTypeEnabled('Publication', $issue->getJournalId());
        $representationPubIdEnabled = $this->isObjectTypeEnabled('Representation', $issue->getJournalId());
        if (!$publicationPubIdEnabled && !$representationPubIdEnabled) {
            return false;
        }

        $pubIdType = $this->getPubIdType();
        $submissionIds = $this->submissionIdsForIssue((int)$issue->getJournalId(), (int)$issue->getId());

        foreach ($submissionIds as $submissionId) {
            $submission = $this->getSubmission($submissionId);
            if (!$submission) {
                continue;
            }
            if ($publicationPubIdEnabled) {
                foreach ($submission->getData('publications') as $publication) {
                    $publicationDao = DAORegistry::getDAO('PublicationDAO');
                    if ($publicationDao && method_exists($publicationDao, 'deletePubId')) {
                        $publicationDao->deletePubId($publication->getId(), $pubIdType);
                    }
                }
                $this->clearStatus((int)$issue->getJournalId(), (int)$submissionId);
            }
            if ($representationPubIdEnabled) {
                foreach ($submission->getData('publications') as $publication) {
                    $representationDao = Application::getRepresentationDAO();
                    $representations = $representationDao->getByPublicationId($publication->getId());
                    foreach ($representations as $representation) {
                        $representationDao->deletePubId($representation->getId(), $pubIdType);
                    }
                }
            }
        }
        return true;
    }

    public function submissionIdsForIssue($contextId, $issueId)
    {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $ids = [];
        foreach (iterator_to_array($submissionDao->getByContextId($contextId)) as $submission) {
            $publication = $submission->getCurrentPublication();
            if ($publication && (int)$publication->getData('issueId') === (int)$issueId) {
                $ids[] = (int)$submission->getId();
            }
        }
        return $ids;
    }
}
