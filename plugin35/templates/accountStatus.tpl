{**
 * plugins/pubIds/sri/templates/accountStatus.tpl
 *
 * Copyright (c) 2026 Scitekhub. Distributed under the GNU GPL v3.
 *
 * Server-rendered account status partial for OJS 3.5. Values come from the normalized
 * RegistrationService response and are escaped before insertion.
 *}

{if $sriAccountStatusAvailable}
    <p><strong>{translate key="plugins.pubIds.sri.manager.settings.status.account" status=$sriAccountStatus|escape}</strong></p>
    <p>{translate key="plugins.pubIds.sri.manager.settings.status.partner" status=$sriPartnerStatus|escape}</p>
    {if $sriBlockedReason}
        <p class="pkp_help" style="color:#b40000;">{translate key="plugins.pubIds.sri.manager.settings.status.blocked" reason=$sriBlockedReason|escape}</p>
    {/if}
    {if $sriExpiresAt}
        <p>{translate key="plugins.pubIds.sri.manager.settings.status.membership" when=$sriExpiresAt|escape days=$sriDaysRemaining|escape}</p>
    {else}
        <p>{translate key="plugins.pubIds.sri.manager.settings.status.membershipNone"}</p>
    {/if}
    <p>{translate key="plugins.pubIds.sri.manager.settings.status.quota" used=$sriSrisUsed|escape quota=$sriSriQuota|escape remaining=$sriSriRemaining|escape}</p>
    <p>{translate key="plugins.pubIds.sri.manager.settings.status.prefixQuota" used=$sriPrefixesUsed|escape assigned=$sriPrefixQuotaAssigned|escape remaining=$sriPrefixRemaining|escape}</p>

    {if $sriConfiguredPrefix && !$sriPrefixesTruncated}
        {if !$sriConfiguredPrefixFound}
            <p class="pkp_help" style="color:#b40000;">{translate key="plugins.pubIds.sri.manager.settings.status.prefixMissing" prefix=$sriConfiguredPrefix|escape}</p>
        {/if}
    {/if}

    {if $sriPrefixes}
        <ul>
            {foreach from=$sriPrefixes item=prefix}
                <li>
                    {translate key="plugins.pubIds.sri.manager.settings.status.prefix" prefix=$prefix.prefix|escape status=$prefix.status|escape}
                    {if $prefix.autoApprove}
                        ({translate key="plugins.pubIds.sri.manager.settings.status.autoApprove"})
                    {/if}
                </li>
            {/foreach}
        </ul>
    {/if}
    {if $sriPrefixesTruncated}
        <p class="pkp_help">{translate key="plugins.pubIds.sri.manager.settings.status.prefixesTruncated"}</p>
    {/if}
{else}
    <p class="pkp_help" style="color:#b40000;">{$sriAccountStatusError|escape}</p>
{/if}

<button type="button" class="pkpButton" data-sri-account-status-refresh="1">
    {translate key="plugins.pubIds.sri.manager.settings.status.refresh"}
</button>
