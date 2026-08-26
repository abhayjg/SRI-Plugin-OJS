{**
 * plugins/pubIds/sri/templates/statusCard.tpl
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Per-article SRI status card rendered inside the publication identifiers
 * form for OJS 3.5. All dynamic values are escaped.
 *}

<div class="pkp_sri_status" style="margin:0.5rem 0;">
	<p>
		<strong>{translate key="plugins.pubIds.sri.status.label"}</strong>:
		<span class="pkp_sri_state">{$sriStateLabel|escape}</span>
	</p>

	{if $sriFullSri}
		<p>
			<strong>{$sriDisplaySri|default:$sriFullSri|escape}</strong>
			{if $sriResolvingUrl}&nbsp;<a href="{$sriResolvingUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.pubIds.sri.status.resolve"}</a>{/if}
		</p>
	{/if}

	{if $sriFullSri === '' && $sriActionSuffix}
		<p class="pkp_help">{translate key="plugins.pubIds.sri.status.preview" suffix=$sriActionSuffix}</p>
	{/if}

	{if $sriReason && $sriState === 'failed'}
		<p class="pkp_help" style="color:#b40000;">{translate key="plugins.pubIds.sri.status.reason" reason=$sriReason}</p>
	{/if}

	{if $sriUpdatedAt}
		<p class="pkp_help">{translate key="plugins.pubIds.sri.status.updatedAt" when=$sriUpdatedAt}</p>
	{/if}

	{if $sriFullSri === ''}
		<p>
			<a class="pkpButton" href="{$sriActionRegisterUrl|escape}">{translate key="plugins.pubIds.sri.action.registerNow"}</a>
		</p>
	{else}
		<p>
			<a class="pkpButton" href="{$sriActionRefreshUrl|escape}">{translate key="plugins.pubIds.sri.action.refresh"}</a>
		</p>
	{/if}

	<form method="post" action="{$sriActionAttachUrl|escape}" style="margin-top:0.5rem;">
		{translate key="plugins.pubIds.sri.action.attachExisting"}:
		<input type="text" name="fullSri" value="" placeholder="sri:2026.1001.example+ABC" maxlength="256" pattern="[^\s]+" required="required" />
		<button class="pkpButton" type="submit">{translate key="plugins.pubIds.sri.action.attach"}</button>
	</form>
</div>
