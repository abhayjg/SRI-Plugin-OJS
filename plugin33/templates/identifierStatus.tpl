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
{if $currentContext}
	{assign var=sriContextId value=$currentContext->getId()}
{elseif $currentJournal}
	{assign var=sriContextId value=$currentJournal->getId()}
{else}
	{assign var=sriContextId value=null}
{/if}
{assign var=enableObjectSri value=$pubIdPlugin->isObjectTypeEnabled($pubObjectType, $sriContextId)}
{if $enableObjectSri}
	{fbvFormArea id="pubIdSriFormArea" class="border" title="plugins.pubIds.sri.displayName"}
		{assign var=storedPubId value=$pubObject->getStoredPubId($pubIdPlugin->getPubIdType())}
		{if $storedPubId}
			{fbvFormSection}
				<p>
					{$storedPubId|escape}<br />
					<span class="pkp_help">{translate key="plugins.pubIds.sri.editor.assigned"}</span>
				</p>
				{if $clearPubIdLinkActionSri}
					{include file="linkAction/linkAction.tpl" action=$clearPubIdLinkActionSri contextId="publicIdentifiersForm"}
				{/if}
			{/fbvFormSection}
		{else}
			{fbvFormSection}
				<p class="pkp_help">{translate key="plugins.pubIds.sri.editor.notRegistered"}</p>
				{fbvElement type="text" label="plugins.pubIds.sri.editor.suffix" id="sriSuffix" value=$pubIdPlugin->getSuffixValue($pubObject, $pubObjectType) maxlength="100" size=$fbvStyles.size.LARGE}
			{/fbvFormSection}
		{/if}
	{/fbvFormArea}
{/if}

{* When viewing Issue Identifiers tab, display all articles in this issue with their SRI identifiers *}
{if $pubObjectType == 'Issue'}
	{assign var=sriArticles value=$pubIdPlugin->getIssueArticlesData($pubObject)}
	{fbvFormArea id="pubIdSriIssueArticlesArea" class="border" title="plugins.pubIds.sri.displayName"}
		{if !empty($sriArticles)}
			{fbvFormSection title="plugins.pubIds.sri.issue.articlesTitle"}
				<div class="sri-issue-articles-list" style="margin-bottom: 15px;">
					<table class="pkpTable" style="width: 100%; border-collapse: collapse; font-size: 13px;">
						<thead>
							<tr style="border-bottom: 2px solid #e0e5eb; text-align: left; background: #f8fafc;">
								<th style="padding: 8px 10px; font-weight: 600;">{translate key="article.article"}</th>
								<th style="padding: 8px 10px; font-weight: 600; width: 240px;">{translate key="plugins.pubIds.sri.displayName"}</th>
								<th style="padding: 8px 10px; font-weight: 600; width: 110px;">{translate key="common.status"}</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$sriArticles item=sriArticle}
								<tr style="border-bottom: 1px solid #edf0f2;">
									<td style="padding: 8px 10px; vertical-align: middle;">
										<strong>#{$sriArticle.submissionId}</strong>: {$sriArticle.title|escape}
									</td>
									<td style="padding: 8px 10px; vertical-align: middle; font-family: monospace;">
										{if $sriArticle.fullSri}
											{if $sriArticle.resolvingUrl}
												<a href="{$sriArticle.resolvingUrl|escape}" target="_blank" rel="noopener noreferrer" style="color: #0066cc; text-decoration: underline; font-weight: 500;">{$sriArticle.displaySri|escape}</a>
											{else}
												{$sriArticle.displaySri|escape}
											{/if}
										{else}
											<span style="color: #8a96a3; font-style: italic;">{translate key="plugins.pubIds.sri.status.none"}</span>
										{/if}
									</td>
									<td style="padding: 8px 10px; vertical-align: middle;">
										{if $sriArticle.state == 'active'}
											<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #e6f7ec; color: #0e703c;">{$sriArticle.stateLabel|escape}</span>
										{elseif $sriArticle.state == 'pending'}
											<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #fff8e6; color: #9c6800;">{$sriArticle.stateLabel|escape}</span>
										{elseif $sriArticle.state == 'failed'}
											<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #fde8e8; color: #bc1c1c;">{$sriArticle.stateLabel|escape}</span>
										{else}
											<span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #f0f2f5; color: #5a6578;">{$sriArticle.stateLabel|escape}</span>
										{/if}
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{/fbvFormSection}
			{if $clearIssueObjectsPubIdsLinkActionSri}
				{fbvFormSection list="true" description="plugins.pubIds.sri.editor.clearIssueObjectsSri.description"}
					{include file="linkAction/linkAction.tpl" action=$clearIssueObjectsPubIdsLinkActionSri contextId="publicIdentifiersForm"}
				{/fbvFormSection}
			{/if}
		{else}
			{fbvFormSection}
				<p class="pkp_help">{translate key="plugins.pubIds.sri.issue.noArticles"}</p>
			{/fbvFormSection}
		{/if}
	{/fbvFormArea}
{/if}
