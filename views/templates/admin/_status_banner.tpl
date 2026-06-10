{**
 * Marques de France — BO Admin — Status Banner (partial)
 *}

<div class="mdf-status-banner {if $mdf_error}mdf-status-error{elseif $mdf_status.connected}mdf-status-ok{else}mdf-status-warning{/if}">
  {if $mdf_error}
    <span class="mdf-status-icon">&#9888;</span>
    {$mdf_error|escape:'html':'UTF-8'}
  {elseif $mdf_status.connected}
    <span class="mdf-status-icon">&#10003;</span>
    {l s='Connected to Marques de France platform.' mod='mdfcforps'}
  {else}
    <span class="mdf-status-icon">&#8987;</span>
    {l s='Connecting to Marques de France platform…' mod='mdfcforps'}
  {/if}
</div>
