{**
 * Marques de France — BO Admin — Product Feed tab
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

        {* Manage products button *}
        <div class="mdf-add-product-row mb-3">
          <button id="mdf-open-manage-btn" class="btn btn-primary btn-sm">
            {l s='Manage products' mod='mdfcforps'}
          </button>
          <span class="text-muted ml-2">
            {l s='%count% product(s) currently in feed' sprintf=['%count%' => $mdf_feed_total] mod='mdfcforps'}
          </span>
        </div>

        {* Manage products panel — shown/hidden by JS *}
        <div id="mdf-manage-panel" style="display:none;" class="mb-4">
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <strong>{l s='Select products to include in feed' mod='mdfcforps'}</strong>
              <button id="mdf-manage-done-btn" class="btn btn-secondary btn-sm">
                {l s='Done' mod='mdfcforps'}
              </button>
            </div>
            <div class="card-body">

              {* Search bar *}
              <div class="input-group mb-3" style="max-width:420px;">
                <input type="text" id="mdf-manage-search" class="form-control"
                       placeholder="{l s='Search by name or reference…' mod='mdfcforps'}">
                <div class="input-group-append">
                  <button id="mdf-manage-search-clear" class="btn btn-outline-secondary" type="button">&times;</button>
                </div>
              </div>

              {* Spinner *}
              <div id="mdf-manage-spinner" style="display:none;" class="mb-2">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                {l s='Loading…' mod='mdfcforps'}
              </div>

              {* Product table *}
              <div class="table-responsive">
                <table class="table table-striped table-sm">
                  <thead class="thead-light">
                    <tr>
                      <th class="text-center" style="width:40px;">
                        <input type="checkbox" id="mdf-manage-select-all"
                               title="{l s='Select / deselect all visible' mod='mdfcforps'}">
                      </th>
                      <th style="width:56px;">{l s='Photo' mod='mdfcforps'}</th>
                      <th>{l s='Product name' mod='mdfcforps'}</th>
                      <th>{l s='Brand' mod='mdfcforps'}</th>
                      <th>{l s='Reference' mod='mdfcforps'}</th>
                      <th>{l s='Availability' mod='mdfcforps'}</th>
                      <th>{l s='Price' mod='mdfcforps'}</th>
                      <th>{l s='Status' mod='mdfcforps'}</th>
                    </tr>
                  </thead>
                  <tbody id="mdf-manage-tbody">
                    <tr>
                      <td colspan="8" class="text-center text-muted py-3">
                        {l s='Click «Manage products» to load your catalog.' mod='mdfcforps'}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              {* Pagination *}
              <div id="mdf-manage-pagination" class="d-flex align-items-center mt-2 flex-wrap"></div>

            </div>
          </div>
        </div>

        {if $mdf_feed_products|@count eq 0}
          <p class="mdf-empty-state">{l s='No products in the feed list yet.' mod='mdfcforps'}</p>
        {else}
          <table class="table table-striped table-sm">
            <thead>
              <tr>
                <th style="width:56px;">{l s='Photo' mod='mdfcforps'}</th>
                <th>{l s='Product name' mod='mdfcforps'}</th>
                <th>{l s='Brand' mod='mdfcforps'}</th>
                <th>{l s='Reference' mod='mdfcforps'}</th>
                <th>{l s='Availability' mod='mdfcforps'}</th>
                <th>{l s='Price' mod='mdfcforps'}</th>
                <th>{l s='Status' mod='mdfcforps'}</th>
              </tr>
            </thead>
            <tbody>
              {foreach from=$mdf_feed_products item=fp}
              <tr>
                <td>
                  {if $fp.image}
                    <img src="{$fp.image|escape:'html':'UTF-8'}" width="40" height="40"
                         style="object-fit:cover;border-radius:3px;" loading="lazy">
                  {else}
                    <span style="display:inline-block;width:40px;height:40px;background:#eee;border-radius:3px;"></span>
                  {/if}
                </td>
                <td>{$fp.name|escape:'html':'UTF-8'}<br><small class="text-muted">#{$fp.id|intval}</small></td>
                <td>{$fp.brand|escape:'html':'UTF-8'}</td>
                <td>{$fp.reference|escape:'html':'UTF-8'}</td>
                <td>{$fp.availability|escape:'html':'UTF-8'}</td>
                <td>{$fp.price|escape:'html':'UTF-8'}</td>
                <td>{$fp.status|escape:'html':'UTF-8'}</td>
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
