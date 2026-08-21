{**
 * plugins/pubIds/sri/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * SRI-Plugin settings form.
 *}

<div class="pkp_notification">
	<p>{translate key="plugins.pubIds.sri.manager.settings.description"}</p>
</div>

<script>
	$(function() {ldelim}
		// Attach the form handler so save is a proper AJAX submit — without
		// this the browser does a plain navigation and dumps the raw
		// JSONMessage response instead of closing the modal.
		$('#sriSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>
<form class="pkp_form" id="sriSettingsForm" method="post" action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="pubIds" plugin=$sriPluginName verb="save"}">
	{csrf}
	{include file="common/formErrors.tpl"}

	{fbvFormArea id="sriObjectsFormArea" title="plugins.pubIds.sri.manager.settings.sriObjects"}
		<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriObjects.description"}</p>
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" label="plugins.pubIds.sri.manager.settings.enablePublicationSri" id="enablePublicationSri" maxlength="40" checked=$enablePublicationSri|compare:true}
			{fbvElement type="checkbox" label="plugins.pubIds.sri.manager.settings.enableRepresentationSri" id="enableRepresentationSri" maxlength="40" checked=$enableRepresentationSri|compare:true}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="sriConnectionFormArea" title="plugins.pubIds.sri.manager.settings.connection"}
		<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.connection.description"}</p>
		{fbvFormSection}
			{fbvElement type="text" id="sriBaseUrl" value=$sriBaseUrl required="true" label="plugins.pubIds.sri.manager.settings.sriBaseUrl" maxlength="255" size=$fbvStyles.size.LARGE}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="sriApiKey" value=$sriApiKey required="true" label="plugins.pubIds.sri.manager.settings.sriApiKey" maxlength="255" size=$fbvStyles.size.LARGE}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriApiKey.description"}</p>
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="sriPrefix" value=$sriPrefix required="true" label="plugins.pubIds.sri.manager.settings.sriPrefix" maxlength="8" size=$fbvStyles.size.SMALL}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriPrefix.description"}</p>
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="sriResolverUrl" value=$sriResolverUrl label="plugins.pubIds.sri.manager.settings.sriResolverUrl" maxlength="255" size=$fbvStyles.size.LARGE}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriResolverUrl.description"}</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="sriAutomationFormArea" title="plugins.pubIds.sri.manager.settings.automation"}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" label="plugins.pubIds.sri.manager.settings.sriAutoRegister" id="sriAutoRegister" maxlength="40" checked=$sriAutoRegister|compare:true}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriAutoRegister.description"}</p>
		{/fbvFormSection}
		{fbvFormSection}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriBulk.label"}</p>
			{include file="linkAction/linkAction.tpl" action=$sriBulkLinkAction contextId="sriSettingsForm"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="sriSuffixFormArea" title="plugins.pubIds.sri.manager.settings.sriSuffix"}
		<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriSuffix.description"}</p>
		{fbvFormSection list="true"}
			{fbvElement type="radio" id="sriSuffixDefault" name="sriSuffix" value="default" label="plugins.pubIds.sri.manager.settings.sriSuffixDefault" checked=$sriSuffix|compare:"default"}
			<span class="instruct">{translate key="plugins.pubIds.sri.manager.settings.sriSuffixDefault.description"}</span>
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="radio" id="sriSuffixManual" name="sriSuffix" value="manual" label="plugins.pubIds.sri.manager.settings.sriSuffixManual" checked=$sriSuffix|compare:"manual"}
		{/fbvFormSection}
		{fbvFormSection list="true"}
			{fbvElement type="radio" id="sriSuffixPattern" name="sriSuffix" value="pattern" label="plugins.pubIds.sri.manager.settings.sriSuffixPattern" checked=$sriSuffix|compare:"pattern"}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriSuffixPattern.example"}</p>
			{fbvElement type="text" label="plugins.pubIds.sri.manager.settings.sriPublicationSuffixPattern" id="sriPublicationSuffixPattern" value=$sriPublicationSuffixPattern maxlength="100" inline=true size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="sriMetadataFormArea" title="plugins.pubIds.sri.manager.settings.metadata"}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" label="plugins.pubIds.sri.manager.settings.sriIncludeDoi" id="sriIncludeDoi" maxlength="40" checked=$sriIncludeDoi|compare:true}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriIncludeDoi.description"}</p>
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="sriFallbackPublisher" value=$sriFallbackPublisher label="plugins.pubIds.sri.manager.settings.sriFallbackPublisher" maxlength="500" size=$fbvStyles.size.LARGE}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.sriFallbackPublisher.description"}</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="sriTimeoutFormArea" title="plugins.pubIds.sri.manager.settings.timeouts"}
		{fbvFormSection}
			{fbvElement type="text" id="sriConnectTimeout" value=$sriConnectTimeout label="plugins.pubIds.sri.manager.settings.sriConnectTimeout" maxlength="3" size=$fbvStyles.size.SMALL inline=true}
			{fbvElement type="text" id="sriTimeout" value=$sriTimeout label="plugins.pubIds.sri.manager.settings.sriTimeout" maxlength="3" size=$fbvStyles.size.SMALL inline=true}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.timeouts.description"}</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="sriClearFormArea" title="plugins.pubIds.sri.manager.settings.clearAll"}
		{fbvFormSection}
			<p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.clearAll.description"}</p>
			{include file="linkAction/linkAction.tpl" action=$sriClearLinkAction contextId="sriSettingsForm"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
