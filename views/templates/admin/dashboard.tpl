{**
 * Marques de France — BO Admin — Dashboard tab
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
    {include file=$mdf_banner_tpl}

    {if $mdf_error}
      <div class="mdf-empty-state">
        <p>{$mdf_error|escape:'html':'UTF-8'}</p>
      </div>
    {else}
      {* KPI cards *}
      <div class="mdf-sales-kpis">
        <div class="mdf-kpi-card">
          <div class="mdf-kpi-value">{if isset($mdf_analytics.totalSales)}{$mdf_analytics.totalSales|intval}{else}—{/if}</div>
          <div class="mdf-kpi-label">{l s='Total Sales' mod='mdfcforps'}</div>
        </div>
        <div class="mdf-kpi-card">
          <div class="mdf-kpi-value">{if isset($mdf_analytics.totalRevenue)}{$mdf_analytics.totalRevenue|string_format:'%.2f'} €{else}—{/if}</div>
          <div class="mdf-kpi-label">{l s='Total Revenue' mod='mdfcforps'}</div>
        </div>
        <div class="mdf-kpi-card">
          <div class="mdf-kpi-value">{if isset($mdf_analytics.totalClicks)}{$mdf_analytics.totalClicks|intval}{else}—{/if}</div>
          <div class="mdf-kpi-label">{l s='Clicks' mod='mdfcforps'}</div>
        </div>
        <div class="mdf-kpi-card">
          <div class="mdf-kpi-value">
            {if isset($mdf_analytics.conversionRate)}{$mdf_analytics.conversionRate|string_format:'%.1f'}%{else}—{/if}
          </div>
          <div class="mdf-kpi-label">{l s='Conversion Rate' mod='mdfcforps'}</div>
        </div>
      </div>

      {* Chart placeholder — JS fills this in *}
      <div class="mdf-chart-wrap">
        <canvas id="mdf-revenue-chart" height="300"></canvas>
      </div>
    {/if}
  </div>
</div>

<script>
window.mdfcforpsAdmin = {
  analyticsData: {$mdf_analytics|json_encode},
  adminUrl: "{$mdf_admin_url|escape:'javascript':'UTF-8'}"
};
</script>
