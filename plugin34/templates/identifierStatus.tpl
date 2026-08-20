{**
 * plugins/pubIds/sri/templates/identifierStatus.tpl
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Included in the (legacy) pubIds metadata edit area for an object. Shows the
 * stored SRI (if any) plus the manual-suffix control, mirroring the DOI
 * plugin's metadata template. The richer status card lives in the modern
 * publication identifiers form (see statusCard.tpl).
 *}

{assign var=pubObjectType value=$pubIdPlugin->getPubObjectType($pubObject)}
{assign var=enableObjectSri value=$pubIdPlugin->isObjectTypeEnabled($pubObjectType, $currentContext->getId())}
{if $enableObjectSri}
	{fbvFormArea id="pubIdSriFormArea" class="border" title="plugins.pubIds.sri.displayName"}
		{assign var=storedPubId value=$pubObject->getStoredPubId($pubIdPlugin->getPubIdType())}
		{if $storedPubId}
			{fbvFormSection}
				<p>
					{$storedPubId|escape}<br />
					<span class="pkp_help">{translate key="plugins.pubIds.sri.editor.assigned"}</span>
				</p>
				{include file="linkAction/linkAction.tpl" action=$clearPubIdLinkActionSri contextId="publicIdentifiersForm"}
			{/fbvFormSection}
		{else}
			{fbvFormSection}
				<p class="pkp_help">{translate key="plugins.pubIds.sri.editor.notRegistered"}</p>
				{fbvElement type="text" label="plugins.pubIds.sri.editor.suffix" id="sriSuffix" value=$pubIdPlugin->getSuffixValue($pubObject, $pubObjectType) maxlength="100" size=$fbvStyles.size.LARGE}
			{/fbvFormSection}
		{/if}
	{/fbvFormArea}
{/if}
