<?php

/**
 * @file plugins/pubIds/sri/form/SriSettingsForm.inc.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * @class SriSettingsForm
 * @ingroup plugins_pubIds_sri
 *
 * @brief Settings form for journal managers to configure SRI-Plugin (OJS 3.3).
 */

import('lib.pkp.classes.form.Form');

class SriSettingsForm extends Form
{
    /** @var int Context (journal) id. */
    private $_contextId;

    /** @var SriPubIdPlugin */
    private $_plugin;

    public function __construct($plugin, $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        $this->addCheck(new FormValidatorUrl($this, 'sriBaseUrl', 'required', 'plugins.pubIds.sri.manager.settings.sriBaseUrlRequired'));
        $this->addCheck(new FormValidator($this, 'sriApiKey', 'required', 'plugins.pubIds.sri.manager.settings.sriApiKeyRequired'));
        $this->addCheck(new FormValidatorRegExp($this, 'sriPrefix', 'required', 'plugins.pubIds.sri.manager.settings.sriPrefixRequired', '/^\d{1,8}$/'));

        $form = $this;
        $this->addCheck(new FormValidatorCustom(
            $this,
            'sriPublicationSuffixPattern',
            'required',
            'plugins.pubIds.sri.manager.settings.sriPublicationSuffixPatternRequired',
            function ($pattern) use ($form) {
                if ($form->getData('sriSuffix') !== 'pattern') {
                    return true;
                }
                return is_string($pattern) && trim($pattern) !== '';
            }
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'sriConnectTimeout',
            'required',
            'plugins.pubIds.sri.manager.settings.sriTimeoutRange',
            function ($value) {
                return is_numeric($value) && (int)$value >= 1 && (int)$value <= 120;
            }
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'sriTimeout',
            'required',
            'plugins.pubIds.sri.manager.settings.sriTimeoutRange',
            function ($value) {
                return is_numeric($value) && (int)$value >= 1 && (int)$value <= 300;
            }
        ));

        $this->setData('sriClearLinkAction', $plugin->getClearAllLinkAction($this->_contextId));
        $this->setData('sriBulkLinkAction', $plugin->getBulkLinkAction($this->_contextId));
        $this->setData('sriPluginName', $plugin->getName());
    }

    //
    // Form overrides
    //

    public function initData()
    {
        $plugin = $this->_plugin;
        $contextId = $this->_contextId;

        $this->setData('enablePublicationSri', (bool)$plugin->setting($contextId, 'enablePublicationSri', true));
        $this->setData('enableRepresentationSri', (bool)$plugin->setting($contextId, 'enableRepresentationSri', false));
        $this->setData('sriBaseUrl', (string)$plugin->setting($contextId, 'sriBaseUrl', ''));
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

    public function readInputData()
    {
        $this->readUserVars($this->_getFormFields());
    }

    public function execute(...$functionArgs)
    {
        $plugin = $this->_plugin;
        $contextId = $this->_contextId;

        foreach ($this->_getFormFields() as $field) {
            $value = $this->getData($field);
            if (in_array($field, ['enablePublicationSri', 'enableRepresentationSri', 'sriAutoRegister', 'sriIncludeDoi'], true)) {
                $value = $value ? '1' : '0';
            } elseif (in_array($field, ['sriConnectTimeout', 'sriTimeout'], true)) {
                $value = (int)$value;
            }
            $plugin->updateSetting($contextId, $field, $value, $this->_settingType($field));
        }

        parent::execute(...$functionArgs);
    }

    //
    // Helpers
    //

    private function _getFormFields()
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

    private function _settingType($field)
    {
        if (in_array($field, ['enablePublicationSri', 'enableRepresentationSri', 'sriAutoRegister', 'sriIncludeDoi'], true)) {
            return 'bool';
        }
        return 'string';
    }
}
