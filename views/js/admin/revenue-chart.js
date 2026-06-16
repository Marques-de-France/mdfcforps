/*
 * 2007-2026 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.
 */

(function () {
  'use strict';

  var stateKey = '__mdfcforpsRevenueChartState';
  var globalState = window[stateKey] || {
    bootstrapped: false,
    chartInstances: {},
    initializedCanvases: {},
  };

  if (globalState.bootstrapped) {
    return;
  }

  globalState.bootstrapped = true;
  window[stateKey] = globalState;

  var chartInstances = globalState.chartInstances;
  var initializedCanvases = globalState.initializedCanvases;

  function toDateFromKey(key) {
    if (!key) {
      return null;
    }

    var parts = String(key).split('-');
    if (parts.length < 2) {
      return null;
    }

    var year = Number(parts[0]);
    var month = Number(parts[1]) - 1;
    var day = parts[2] ? Number(parts[2]) : 1;
    var date = new Date(year, month, day);

    return Number.isNaN(date.getTime()) ? null : date;
  }

  function toIsoDate(date) {
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
  }

  function toIsoMonth(date) {
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    return year + '-' + month;
  }

  function normalizeAnalytics(raw) {
    raw = raw || {};

    if (Array.isArray(raw.data)) {
      return raw.data.map(function (item) {
        return {
          date: String(item.date || ''),
          revenue: Number(item.revenue || 0),
          conversions: Number(item.conversions || 0),
        };
      });
    }

    return [];
  }

  function formatDateLabel(dateKey) {
    if (!dateKey) {
      return '';
    }

    var isoMatch = /^\d{4}-\d{2}(-\d{2})?$/.test(dateKey);
    if (!isoMatch) {
      return dateKey;
    }

    var parts = dateKey.split('-');
    var year = Number(parts[0]);
    var month = Number(parts[1]) - 1;
    var day = parts[2] ? Number(parts[2]) : 1;
    var dateObj = new Date(year, month, day);

    return new Intl.DateTimeFormat('fr-FR', {
      day: parts[2] ? 'numeric' : undefined,
      month: 'short',
      year: 'numeric',
    }).format(dateObj);
  }

  function formatCurrency(value, currency, min, max) {
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: currency,
      minimumFractionDigits: min,
      maximumFractionDigits: max,
    }).format(Number(value) || 0);
  }

  function getLatestDate(rows) {
    return rows.reduce(function (latest, row) {
      if (!row.dateObj) {
        return latest;
      }
      if (!latest || row.dateObj.getTime() > latest.getTime()) {
        return row.dateObj;
      }
      return latest;
    }, null);
  }

  function calculateRangeBounds(latestDate, rangeKey) {
    if (!latestDate) {
      return { start: null, end: null };
    }

    var end = new Date(latestDate.getFullYear(), latestDate.getMonth(), latestDate.getDate());
    var start = new Date(end.getTime());

    if (rangeKey === '7d' || rangeKey === '28d' || rangeKey === '90d') {
      var days = Number(rangeKey.replace('d', '')) || 28;
      start.setDate(end.getDate() - (days - 1));
      return { start: start, end: end };
    }

    if (rangeKey === '12m') {
      start = new Date(end.getFullYear(), end.getMonth() - 11, 1);
      return { start: start, end: end };
    }

    if (rangeKey === 'year') {
      var previousYear = end.getFullYear() - 1;
      return {
        start: new Date(previousYear, 0, 1),
        end: new Date(previousYear, 11, 31),
      };
    }

    if (rangeKey === 'this_year') {
      var year = end.getFullYear();
      return {
        start: new Date(year, 0, 1),
        end: end,
      };
    }

    return { start: null, end: end };
  }

  function filterRowsByRange(rows, rangeKey) {
    var latestDate = getLatestDate(rows);
    var bounds = calculateRangeBounds(latestDate, rangeKey);

    if (!bounds.start || !bounds.end) {
      return rows.slice();
    }

    var startTs = bounds.start.getTime();
    var endTs = bounds.end.getTime();

    return rows.filter(function (row) {
      if (!row.dateObj) {
        return false;
      }
      var ts = row.dateObj.getTime();
      return ts >= startTs && ts <= endTs;
    });
  }

  function aggregateMap(rows, granularity) {
    return rows.reduce(function (acc, row) {
      if (!row.dateObj) {
        return acc;
      }

      var key = granularity === 'month' ? toIsoMonth(row.dateObj) : toIsoDate(row.dateObj);
      if (!acc[key]) {
        acc[key] = { key: key, revenue: 0, conversions: 0, dateObj: toDateFromKey(key) };
      }

      acc[key].revenue += Number(row.revenue || 0);
      acc[key].conversions += Number(row.conversions || 0);
      return acc;
    }, {});
  }

  function buildDenseSeries(rows, granularity, bounds) {
    if (!bounds || !bounds.start || !bounds.end) {
      return [];
    }

    var groups = aggregateMap(rows, granularity);
    var points = [];
    var cursor;
    var end;

    if (granularity === 'month') {
      cursor = new Date(bounds.start.getFullYear(), bounds.start.getMonth(), 1);
      end = new Date(bounds.end.getFullYear(), bounds.end.getMonth(), 1);

      while (cursor.getTime() <= end.getTime()) {
        var monthKey = toIsoMonth(cursor);
        points.push(groups[monthKey] || {
          key: monthKey,
          revenue: 0,
          conversions: 0,
          dateObj: toDateFromKey(monthKey),
        });
        cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
      }

      return points;
    }

    cursor = new Date(bounds.start.getFullYear(), bounds.start.getMonth(), bounds.start.getDate());
    end = new Date(bounds.end.getFullYear(), bounds.end.getMonth(), bounds.end.getDate());

    while (cursor.getTime() <= end.getTime()) {
      var dayKey = toIsoDate(cursor);
      points.push(groups[dayKey] || {
        key: dayKey,
        revenue: 0,
        conversions: 0,
        dateObj: toDateFromKey(dayKey),
      });
      cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1);
    }

    return points;
  }

  function showTooltip(tooltipEl, x, y, html) {
    if (!tooltipEl) {
      return;
    }
    tooltipEl.innerHTML = html;
    tooltipEl.style.left = x + 'px';
    tooltipEl.style.top = y + 'px';
    tooltipEl.classList.remove('d-none');
  }

  function hideTooltip(tooltipEl) {
    if (tooltipEl) {
      tooltipEl.classList.add('d-none');
    }
  }

  function applyFallbackStyles(canvas, tooltip, rangeSelect, granularitySelect) {
    var container = canvas ? canvas.parentElement : null;

    if (container) {
      container.style.position = 'relative';
      container.style.height = '280px';
      container.style.width = '100%';
    }

    if (canvas) {
      canvas.style.display = 'block';
      canvas.style.width = '100%';
      canvas.style.height = '100%';
    }

    if (tooltip) {
      tooltip.style.position = 'absolute';
      tooltip.style.pointerEvents = 'none';
      tooltip.style.background = 'rgba(5, 20, 64, 0.95)';
      tooltip.style.color = '#fff';
      tooltip.style.padding = '6px 8px';
      tooltip.style.borderRadius = '4px';
      tooltip.style.fontSize = '12px';
      tooltip.style.lineHeight = '1.35';
      tooltip.style.whiteSpace = 'nowrap';
      tooltip.style.transform = 'translate(-50%, calc(-100% - 10px))';
      tooltip.style.zIndex = '2';
    }

    [rangeSelect, granularitySelect].forEach(function (el) {
      if (!el) {
        return;
      }
      el.style.width = 'auto';
      el.style.minWidth = '180px';
      el.style.maxWidth = '100%';
      el.style.display = 'inline-block';
    });
  }

  function drawChart(canvas, rows, config, existingChart) {
    if (typeof window.Chart === 'undefined') {
      return null;
    }

    var labels = rows.map(function (item) { return formatDateLabel(item.key); });
    var revenues = rows.map(function (item) { return Number(item.revenue.toFixed(2)); });
    var conversions = rows.map(function (item) { return Number(item.conversions || 0); });

    var maxRevenue = Math.max.apply(null, revenues.concat([0]));
    var maxConversions = Math.max.apply(null, conversions.concat([0]));

    var data = {
      labels: labels,
      datasets: [
        {
          type: 'line',
          label: config.revenueLabel || 'Revenue',
          data: revenues,
          borderColor: '#051440',
          backgroundColor: '#051440',
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 4,
          pointBackgroundColor: '#051440',
          tension: 0.25,
          yAxisID: 'y',
        },
        {
          type: 'bar',
          label: config.salesLabel || 'Sales',
          data: conversions,
          backgroundColor: 'rgba(255,102,84,0.65)',
          borderColor: 'rgba(255,102,84,0.85)',
          borderWidth: 1,
          yAxisID: 'y1',
          barPercentage: 0.65,
          categoryPercentage: 0.8,
        },
      ],
    };

    var options = {
      responsive: true,
      maintainAspectRatio: false,
      animation: {
        duration: 700,
        easing: 'easeOutQuart',
      },
      interaction: {
        mode: 'index',
        intersect: false,
      },
      plugins: {
        legend: {
          position: 'bottom',
          align: 'center',
          labels: {
            boxWidth: 14,
            boxHeight: 10,
            color: '#495057',
          },
        },
        tooltip: {
          callbacks: {
            title: function (items) {
              return items && items.length ? String(items[0].label || '') : '';
            },
            label: function (ctx) {
              var label = ctx.dataset && ctx.dataset.label ? ctx.dataset.label + ': ' : '';
              if (ctx.dataset && ctx.dataset.yAxisID === 'y') {
                return label + formatCurrency(ctx.parsed.y, config.currency || 'EUR', 2, 2);
              }
              return label + String(Math.round(ctx.parsed.y || 0));
            },
          },
        },
      },
      scales: {
        x: {
          grid: {
            display: false,
          },
          ticks: {
            color: '#6c757d',
            autoSkip: true,
            maxTicksLimit: 8,
          },
        },
        y: {
          beginAtZero: true,
          suggestedMax: Math.max(10, Math.ceil(maxRevenue * 1.15)),
          position: 'left',
          ticks: {
            color: '#051440',
            callback: function (value) {
              return formatCurrency(value, config.currency || 'EUR', 0, 0);
            },
          },
          grid: {
            color: '#e9ecef',
          },
        },
        y1: {
          beginAtZero: true,
          suggestedMax: Math.max(5, Math.ceil(maxConversions * 1.15)),
          position: 'right',
          ticks: {
            color: '#ed2e38',
            precision: 0,
            stepSize: 1,
            callback: function (value) {
              if (Math.round(value) !== value) {
                return '';
              }
              return String(value);
            },
          },
          grid: {
            drawOnChartArea: false,
          },
        },
      },
    };

    if (existingChart) {
      existingChart.data = data;
      existingChart.options = options;
      existingChart.update();
      return existingChart;
    }

    return new window.Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: data,
      options: options,
    });
  }

  function initChart(config) {
    var canvas = document.getElementById(config.canvasId || '');
    var tooltip = document.getElementById(config.tooltipId || '');
    var emptyEl = document.getElementById(config.emptyId || '');
    var revenueEl = config.revenueKpiId ? document.getElementById(config.revenueKpiId) : null;
    var salesEl = config.salesKpiId ? document.getElementById(config.salesKpiId) : null;
    var rangeSelect = config.rangeSelectId ? document.getElementById(config.rangeSelectId) : null;
    var granularitySelect = config.granularitySelectId ? document.getElementById(config.granularitySelectId) : null;

    if (!canvas) {
      return;
    }

    var canvasKey = String(config.canvasId || '');
    if (canvasKey && initializedCanvases[canvasKey]) {
      return;
    }
    if (canvasKey) {
      initializedCanvases[canvasKey] = true;
    }

    applyFallbackStyles(canvas, tooltip, rangeSelect, granularitySelect);

    var baseRows = normalizeAnalytics(config.analytics)
      .map(function (row) {
        var dateObj = toDateFromKey(row.date);
        if (!dateObj) {
          return null;
        }
        return {
          date: row.date,
          dateObj: dateObj,
          revenue: Number(row.revenue || 0),
          conversions: Number(row.conversions || 0),
        };
      })
      .filter(Boolean)
      .sort(function (a, b) { return a.dateObj.getTime() - b.dateObj.getTime(); });

    var state = {
      range: rangeSelect ? rangeSelect.value : (config.defaultRange || '28d'),
      granularity: granularitySelect ? granularitySelect.value : (config.defaultGranularity || 'day'),
      chart: chartInstances[config.canvasId || ''] || null,
    };

    function render() {
      var latestDate = getLatestDate(baseRows);
      var bounds = calculateRangeBounds(latestDate, state.range);
      var filtered = filterRowsByRange(baseRows, state.range);
      var rows = buildDenseSeries(filtered, state.granularity, bounds);
      var totalRevenue = rows.reduce(function (sum, item) { return sum + item.revenue; }, 0);
      var totalSales = rows.reduce(function (sum, item) { return sum + item.conversions; }, 0);

      if (revenueEl) {
        revenueEl.textContent = formatCurrency(totalRevenue, String(config.currency || 'EUR'), 2, 2);
      }

      if (salesEl) {
        salesEl.textContent = String(totalSales);
      }

      if (!rows.length) {
        if (emptyEl) {
          emptyEl.textContent = config.emptyLabel || 'No data for the selected period.';
          emptyEl.classList.remove('d-none');
        }
        if (state.chart) {
          state.chart.destroy();
          state.chart = null;
          chartInstances[config.canvasId || ''] = null;
        }
        var c = canvas.getContext('2d');
        c.clearRect(0, 0, canvas.width, canvas.height);
        return;
      }

      if (emptyEl) {
        emptyEl.classList.add('d-none');
      }

      hideTooltip(tooltip);
      state.chart = drawChart(canvas, rows, config, state.chart);
      chartInstances[config.canvasId || ''] = state.chart;
    }

    if (rangeSelect) {
      rangeSelect.addEventListener('change', function () {
        state.range = rangeSelect.value;
        render();
      });
    }

    if (granularitySelect) {
      granularitySelect.addEventListener('change', function () {
        state.granularity = granularitySelect.value;
        if (state.granularity === 'month' && rangeSelect) {
          rangeSelect.value = '12m';
          state.range = '12m';
        }
        render();
      });
    }

    window.addEventListener('resize', render);
    render();
  }

  function initAllCharts() {
    var configs = Array.isArray(window.mdfcforpsRevenueCharts) ? window.mdfcforpsRevenueCharts : [];
    var uniqueByCanvas = {};

    configs.forEach(function (cfg) {
      var key = String((cfg && cfg.canvasId) || '');
      if (!key) {
        return;
      }
      uniqueByCanvas[key] = cfg;
    });

    Object.keys(uniqueByCanvas).forEach(function (canvasId) {
      initChart(uniqueByCanvas[canvasId]);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllCharts);
  } else {
    initAllCharts();
  }
})();
