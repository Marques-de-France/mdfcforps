{**
 * Marques de France — BO Admin — Sales tab
 *}

<div class="mdf-admin-wrap">
  <nav class="mdf-tabs">
    <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=dashboard" class="mdf-tab {if $mdf_tab eq 'dashboard'}active{/if}">
      {l s='Dashboard' mod='mdfcforps'}
    </a>
    <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=feed" class="mdf-tab {if $mdf_tab eq 'feed'}active{/if}">
      {l s='Product Feed' mod='mdfcforps'}
    </a>
    <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=sales" class="mdf-tab {if $mdf_tab eq 'sales'}active{/if}">
      {l s='Sales' mod='mdfcforps'}
    </a>
  </nav>

  <div class="mdf-tab-content">
    <h2>{l s='Attributed Sales' mod='mdfcforps'}</h2>

    {if $mdf_sales|@count eq 0}
      <p class="mdf-empty-state">{l s='No sales recorded yet.' mod='mdfcforps'}</p>
    {else}
      <table class="mdf-table">
        <thead>
          <tr>
            <th>{l s='Reference' mod='mdfcforps'}</th>
            <th>{l s='Amount' mod='mdfcforps'}</th>
            <th>{l s='Currency' mod='mdfcforps'}</th>
            <th>{l s='Source' mod='mdfcforps'}</th>
            <th>{l s='Status' mod='mdfcforps'}</th>
            <th>{l s='Synced' mod='mdfcforps'}</th>
            <th>{l s='Date' mod='mdfcforps'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$mdf_sales item=sale}
          <tr>
            <td>{$sale.order_reference|escape:'html':'UTF-8'}</td>
            <td>{$sale.amount|string_format:'%.2f'}</td>
            <td>{$sale.currency|escape:'html':'UTF-8'}</td>
            <td><span class="mdf-badge mdf-badge--source">{$sale.attribution_source|escape:'html':'UTF-8'}</span></td>
            <td>
              <span class="mdf-badge mdf-badge--{$sale.status|escape:'html':'UTF-8'}">
                {$sale.status|escape:'html':'UTF-8'}
              </span>
            </td>
            <td>
              {if $sale.hub_synced eq 1}
                <span class="mdf-badge mdf-badge--synced">{l s='Yes' mod='mdfcforps'}</span>
              {elseif $sale.hub_sync_attempts >= 5}
                <span class="mdf-badge mdf-badge--error">{l s='Failed' mod='mdfcforps'}</span>
              {else}
                <span class="mdf-badge mdf-badge--pending">{l s='Pending' mod='mdfcforps'}</span>
              {/if}
            </td>
            <td>{$sale.created_at|escape:'html':'UTF-8'}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>

      {* Pagination *}
      {assign var=total_pages value=ceil($mdf_total/$mdf_per_page)}
      {if $total_pages > 1}
        <div class="mdf-pagination">
          {if $mdf_page > 1}
            <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=sales&sales_page={$mdf_page-1}">&laquo; {l s='Prev' mod='mdfcforps'}</a>
          {/if}
          <span>{l s='Page %page% of %total%' sprintf=['%page%' => $mdf_page, '%total%' => $total_pages] mod='mdfcforps'}</span>
          {if $mdf_page < $total_pages}
            <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=sales&sales_page={$mdf_page+1}">{l s='Next' mod='mdfcforps'} &raquo;</a>
          {/if}
        </div>
      {/if}
    {/if}
  </div>
</div>
