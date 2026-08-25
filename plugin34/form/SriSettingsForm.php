<?php

/**
 * @file plugins/pubIds/sri/form/SriSettingsForm.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * @class SriSettingsForm
 * @ingroup plugins_pubIds_sri
 *
 * @brief Settings form for journal managers to configure SRI-Plugin.
 */

namespace APP\plugins\pubIds\sri\form;

use APP\plugins\pubIds\sri\SriPubIdPlugin;
use PKP\form\Form;

class SriSettingsForm extends Form
{
    /** Default production SRI API base URL (pre-filled for new journals). */
    public const DEFAULT_BASE_URL = 'https://api-sri.scitekhub.com/api/v1';

    /** @var int Context (journal) id. */
    private int $_contextId;

    /** @var SriPubIdPlugin */
    private SriPubIdPlugin $_plugin;

    public function __construct(SriPubIdPlugin $plugin, int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));

        // Use a permissive regex instead of OJS's built-in URL validator, which
        // rejects localhost and private IPs via jQuery Validate's client-side rules.
        $this->addCheck(new \PKP\form\validation\FormValidatorRegExp(
            $this,
            'sriBaseUrl',
            'required',
            'plugins.pubIds.sri.manager.settings.sriBaseUrlRequired',
            '/^https?:\/\/[a-zA-Z0-9.\-]+(:\d+)?(\/.*)?$/'
        ));
        $this->addCheck(new \PKP\form\validation\FormValidator(
            $this,
            'sriApiKey',
            'required',
            'plugins.pubIds.sri.manager.settings.sriApiKeyRequired'
        ));
        $this->addCheck(new \PKP\form\validation\FormValidatorRegExp(
            $this,
            'sriPrefix',
            'required',
            'plugins.pubIds.sri.manager.settings.sriPrefixRequired',
            '/^\d{1,8}$/'
        ));

        $form = $this;
        $this->addCheck(new \PKP\form\validation\FormValidatorCustom(
            $this,
            'sriPublicationSuffixPattern',
            'required',
            'plugins.pubIds.sri.manager.settings.sriPublicationSuffixPatternRequired',
            static function ($pattern) use ($form) {
                if ($form->getData('sriSuffix') !== 'pattern') {
                    return true;
                }
                return is_string($pattern) && trim($pattern) !== '';
            }
        ));

        $this->addCheck(new \PKP\form\validation\FormValidatorCustom(
            $this,
            'sriConnectTimeout',
            'required',
            'plugins.pubIds.sri.manager.settings.sriTimeoutRange',
            static function ($value) {
                return is_numeric($value) && (int)$value >= 1 && (int)$value <= 120;
            }
        ));
        $this->addCheck(new \PKP\form\validation\FormValidatorCustom(
            $this,
            'sriTimeout',
            'required',
            'plugins.pubIds.sri.manager.settings.sriTimeoutRange',
            static function ($value) {
                return is_numeric($value) && (int)$value >= 1 && (int)$value <= 300;
            }
        ));

        // A "clear all SRIs" action surfaced in the settings form.
        $this->setData('sriClearLinkAction', $plugin->getClearAllLinkAction($this->_contextId));
        $this->setData('sriBulkLinkAction', $plugin->getBulkLinkAction($this->_contextId));
        $this->setData('sriPluginName', $plugin->getName());
        $this->setData('sriPluginVersion', $plugin::VERSION);
        $this->setData('sriAccountStatusUrl', $plugin->getAccountStatusUrl());
        $this->setData(
            'sriAccountStatusUnavailable',
            __('plugins.pubIds.sri.manager.settings.status.unavailable')
        );
        $this->setData(
            'sriAccountStatusInitial',
            __('plugins.pubIds.sri.manager.settings.status.initial')
        );
        $this->setData(
            'sriAccountStatusLoading',
            __('plugins.pubIds.sri.manager.settings.status.loading')
        );
        $this->setData(
            'sriAccountStatusRefresh',
            __('plugins.pubIds.sri.manager.settings.status.refresh')
        );
    }

    //
    // Form overrides
    //

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $plugin = $this->_plugin;
        $contextId = $this->_contextId;

        $this->setData('enablePublicationSri', (bool)$plugin->setting($contextId, 'enablePublicationSri', true));
        $this->setData('enableRepresentationSri', (bool)$plugin->setting($contextId, 'enableRepresentationSri', false));
        $this->setData('sriBaseUrl', (string)$plugin->setting($contextId, 'sriBaseUrl', '') ?: self::DEFAULT_BASE_URL);
        $this->setData('sriApiKey', (string)$plugin->setting($contextId, 'sriApiKey', ''));
        $this->setData('sriPrefix', (string)$plugin->setting($contextId, 'sriPrefix', ''));
        $this->setData('sriResolverUrl', (string)$plugin->setting($contextId, 'sriResolverUrl', ''));
        $this->setData('sriAutoRegister', (bool)$plugin->setting($contextId, 'sriAutoRegister', true));
        $this->setData('sriSuffix', (string)$plugin->setting($contextId, 'sriSuffix', 'default'));
        $this->setData('sriPublicationSuffixPattern', (string)$plugin->setting($contextId, 'sriPublicationSuffixPattern', ''));
        $this->setData('sriRepresentationSuffixPattern', (string)$plugin->setting($contextId, 'sriRepresentationSuffixPattern', ''));
        $this->setData('sriIncludeDoi', (bool)$plugin->setting($contextId, 'sriIncludeDoi', true));
        $this->setData('sriFallbackPublisher', (string)$plugin->setting($contextId, 'sriFallbackPublisher', ''));
        $this->setData('sriConnectTimeout', (int)$plugin->setting($contextId, 'sriConnectTimeout', 10));
        $this->setData('sriTimeout', (int)$plugin->setting($contextId, 'sriTimeout', 30));
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars($this->getFormFields());
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $plugin = $this->_plugin;
        $contextId = $this->_contextId;

        foreach ($this->getFormFields() as $field) {
            $value = $this->getData($field);
            if (in_array($field, ['enablePublicationSri', 'enableRepresentationSri', 'sriAutoRegister', 'sriIncludeDoi'], true)) {
                $value = $value ? '1' : '0';
            } elseif (in_array($field, ['sriConnectTimeout', 'sriTimeout'], true)) {
                $value = (int)$value;
            }
            $plugin->updateSetting($contextId, $field, $value, $this->settingType($field));
        }

        parent::execute(...$functionArgs);
    }

    //
    // Helpers
    //

    /**
     * @return string[] Field names read from the form.
     */
    private function getFormFields(): array
    {
        return [
            'enablePublicationSri',
            'enableRepresentationSri',
            'sriBaseUrl',
            'sriApiKey',
            'sriPrefix',
            'sriResolverUrl',
            'sriAutoRegister',
            'sriSuffix',
            'sriPublicationSuffixPattern',
            'sriRepresentationSuffixPattern',
            'sriIncludeDoi',
            'sriFallbackPublisher',
            'sriConnectTimeout',
            'sriTimeout',
        ];
    }

    private function settingType(string $field): string
    {
        if (in_array($field, ['enablePublicationSri', 'enableRepresentationSri', 'sriAutoRegister', 'sriIncludeDoi'], true)) {
            return 'bool';
        }
        return 'string';
    }
}
