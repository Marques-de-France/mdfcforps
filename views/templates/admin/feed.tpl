{**
 * Marques de France — BO Admin — Product Feed tab
 *}

<div class="mdf-admin-wrap">
  <nav class="mdf-tabs">
    <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=dashboard" class="mdf-tab {if $mdf_tab eq 'dashboard'}active{/if}">
      {l s='Dashboard' mod='mdfcforps'}
    </a>
    <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=sales" class="mdf-tab {if $mdf_tab eq 'sales'}active{/if}">
      {l s='Sales' mod='mdfcforps'}
    </a>
    <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=feed" class="mdf-tab {if $mdf_tab eq 'feed'}active{/if}">
      {l s='Product Feed' mod='mdfcforps'}
    </a>
  </nav>

  <div class="mdf-tab-content">
    <h2>{l s='Product Feed' mod='mdfcforps'}</h2>

    {* Feed mode toggle *}
    <form method="post" action="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=feed" class="mdf-feed-mode-form">
      <input type="hidden" name="mdf_update_feed_mode" value="1">
      <div class="mdf-feed-mode-row">
        <label>
          <input type="radio" name="feed_mode" value="TAG" {if $mdf_feed_mode eq 'TAG'}checked{/if}>
          {l s='Tag mode — include products tagged «marques-de-france»' mod='mdfcforps'}
        </label>
        <label>
          <input type="radio" name="feed_mode" value="SERVERLIST" {if $mdf_feed_mode eq 'SERVERLIST'}checked{/if}>
          {l s='Manual list — include only products added below' mod='mdfcforps'}
        </label>
        <button type="submit" class="btn btn-default btn-sm">{l s='Save' mod='mdfcforps'}</button>
      </div>
    </form>

    {* SERVERLIST product management *}
    {if $mdf_feed_mode eq 'SERVERLIST'}
      <div class="mdf-serverlist-panel">
        <h3>{l s='Products in feed' mod='mdfcforps'}</h3>

        {* Add product by ID *}
        <div class="mdf-add-product-row">
          <input type="number" id="mdf-add-product-id" placeholder="{l s='Product ID' mod='mdfcforps'}" min="1">
          <button id="mdf-add-product-btn" class="btn btn-primary btn-sm">
            {l s='Add product' mod='mdfcforps'}
          </button>
        </div>

        {if $mdf_feed_products|@count eq 0}
          <p class="mdf-empty-state">{l s='No products in the feed list yet.' mod='mdfcforps'}</p>
        {else}
          <table class="mdf-table">
            <thead>
              <tr>
                <th>{l s='ID' mod='mdfcforps'}</th>
                <th>{l s='Product name' mod='mdfcforps'}</th>
                <th>{l s='Added' mod='mdfcforps'}</th>
                <th>{l s='Remove' mod='mdfcforps'}</th>
              </tr>
            </thead>
            <tbody>
              {foreach from=$mdf_feed_products item=fp}
              <tr>
                <td>{$fp.product_id|intval}</td>
                <td>{$fp.product_name|escape:'html':'UTF-8'}</td>
                <td>{$fp.added_at|escape:'html':'UTF-8'}</td>
                <td>
                  <button class="btn btn-danger btn-sm mdf-remove-product"
                          data-product-id="{$fp.product_id|intval}"
                          data-admin-url="{$mdf_admin_url|escape:'html':'UTF-8'}">
                    &times;
                  </button>
                </td>
              </tr>
              {/foreach}
            </tbody>
          </table>

          {assign var=total_pages value=ceil($mdf_feed_total/25)}
          {if $total_pages > 1}
            <div class="mdf-pagination">
              {if $mdf_feed_page > 1}
                <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=feed&feed_page={$mdf_feed_page-1}">&laquo; {l s='Prev' mod='mdfcforps'}</a>
              {/if}
              <span>{l s='Page %page% of %total%' sprintf=['%page%' => $mdf_feed_page, '%total%' => $total_pages] mod='mdfcforps'}</span>
              {if $mdf_feed_page < $total_pages}
                <a href="{$mdf_admin_url|escape:'html':'UTF-8'}&tab=feed&feed_page={$mdf_feed_page+1}">{l s='Next' mod='mdfcforps'} &raquo;</a>
              {/if}
            </div>
          {/if}
        {/if}
      </div>
    {/if}
  </div>
</div>

<script>
window.mdfcforpsFeed = {
  adminUrl: "{$mdf_admin_url|escape:'javascript':'UTF-8'}"
};
</script>
