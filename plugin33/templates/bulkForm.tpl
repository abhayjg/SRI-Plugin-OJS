{**
 * plugins/pubIds/sri/templates/bulkForm.tpl
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * "Register back catalog" form: pick an issue, register all of its published
 * articles that do not already have an SRI. Posts to the plugin manage verb
 * 'bulkRun' (CSRF-protected). A generic FormHandler performs the AJAX submit.
 *}

<div class="pkp_notification">
	<p>{translate key="plugins.pubIds.sri.bulk.description"}</p>
</div>

<form class="pkp_form" id="sriBulkForm" method="post" action="{$sriActionUrl|escape}">
	{csrf}
	{fbvFormArea id="sriBulkFormArea" title="plugins.pubIds.sri.bulk.title"}
		{fbvFormSection}
			{fbvElement type="select" label="plugins.pubIds.sri.bulk.issue" id="issueId" from=$sriIssues required="true"}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="plugins.pubIds.sri.bulk.submit"}
</form>

<script>
	$('#sriBulkForm').pkpHandler('$.pkp.controllers.form.FormHandler');
</script>
