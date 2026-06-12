(function () {
  'use strict';

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

  function drawChart(canvas, rows, config) {
    var dpr = window.devicePixelRatio || 1;
    var width = canvas.clientWidth;
    var height = canvas.clientHeight;
    if (!width || !height) {
      return;
    }

    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);

    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);

    var labels = rows.map(function (item) { return formatDateLabel(item.key); });
    var revenues = rows.map(function (item) { return Number(item.revenue.toFixed(2)); });
    var conversions = rows.map(function (item) { return item.conversions; });

    var padding = { top: 24, right: 96, bottom: 58, left: 78 };
    var rightGutter = 8;
    var chartWidth = width - padding.left - padding.right - rightGutter;
    var chartHeight = height - padding.top - padding.bottom;

    var maxRevenue = Math.max.apply(null, revenues.concat([0]));
    var maxConversions = Math.max.apply(null, conversions.concat([0]));
    var yMaxRevenue = Math.max(10, Math.ceil(maxRevenue * 1.15));
    var yMaxConversions = Math.max(5, Math.ceil(maxConversions * 1.15));

    function xAt(index) {
      if (labels.length <= 1) {
        return padding.left + chartWidth / 2;
      }
      return padding.left + (chartWidth * index) / (labels.length - 1);
    }

    function yRevenue(value) {
      return padding.top + chartHeight - (value / yMaxRevenue) * chartHeight;
    }

    function yConversions(value) {
      return padding.top + chartHeight - (value / yMaxConversions) * chartHeight;
    }

    ctx.strokeStyle = '#e9ecef';
    ctx.lineWidth = 1;
    var gridSteps = 4;
    for (var g = 0; g <= gridSteps; g += 1) {
      var gy = padding.top + (chartHeight * g) / gridSteps;
      ctx.beginPath();
      ctx.moveTo(padding.left, gy);
      ctx.lineTo(width - padding.right - rightGutter, gy);
      ctx.stroke();
    }

    var barWidth = Math.max(10, Math.min(30, chartWidth / Math.max(labels.length, 1) / 2));
    ctx.fillStyle = 'rgba(255,102,84,0.65)';
    conversions.forEach(function (value, index) {
      var x = xAt(index);
      var y = yConversions(value);
      var h = padding.top + chartHeight - y;
      ctx.fillRect(x - barWidth / 2, y, barWidth, h);
    });

    ctx.strokeStyle = '#051440';
    ctx.lineWidth = 2;
    ctx.beginPath();
    revenues.forEach(function (value, index) {
      var x = xAt(index);
      var y = yRevenue(value);
      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });
    ctx.stroke();

    ctx.fillStyle = '#051440';
    revenues.forEach(function (value, index) {
      var x = xAt(index);
      var y = yRevenue(value);
      ctx.beginPath();
      ctx.arc(x, y, 3, 0, Math.PI * 2);
      ctx.fill();
    });

    ctx.font = '11px Arial';
    ctx.fillStyle = '#6c757d';
    ctx.textAlign = 'center';
    var maxTicks = 8;
    var labelStep = Math.max(1, Math.ceil(labels.length / maxTicks));
    labels.forEach(function (label, index) {
      if (index % labelStep !== 0 && index !== labels.length - 1) {
        return;
      }
      var x = xAt(index);
      ctx.fillText(label, x, height - 12);
    });

    ctx.textAlign = 'right';
    ctx.fillStyle = '#051440';
    for (var i = 0; i <= gridSteps; i += 1) {
      var revVal = (yMaxRevenue * (gridSteps - i)) / gridSteps;
      var yLeft = padding.top + (chartHeight * i) / gridSteps + 4;
      ctx.fillText(formatCurrency(revVal, config.currency || 'EUR', 0, 0), padding.left - 8, yLeft);
    }

    ctx.textAlign = 'left';
    ctx.fillStyle = '#ed2e38';
    for (var j = 0; j <= gridSteps; j += 1) {
      var convVal = (yMaxConversions * (gridSteps - j)) / gridSteps;
      var yRight = padding.top + (chartHeight * j) / gridSteps + 4;
      ctx.fillText(Math.round(convVal).toString(), width - padding.right + 20, yRight);
    }

    var legendY = height - 30;
    ctx.fillStyle = '#051440';
    ctx.fillRect(padding.left, legendY - 3, 12, 3);
    ctx.fillStyle = '#495057';
    ctx.textAlign = 'left';
    ctx.fillText(config.revenueLabel || 'Revenue', padding.left + 18, legendY + 2);

    ctx.fillStyle = 'rgba(255,102,84,0.65)';
    ctx.fillRect(padding.left + 130, legendY - 8, 12, 10);
    ctx.fillStyle = '#495057';
    ctx.fillText(config.salesLabel || 'Sales', padding.left + 148, legendY + 2);

    var points = rows.map(function (row, index) {
      return {
        index: index,
        x: xAt(index),
        yRevenue: yRevenue(revenues[index]),
        yConversions: yConversions(conversions[index]),
        key: row.key,
        revenue: revenues[index],
        conversions: conversions[index],
      };
    });

    return { points: points };
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
      points: [],
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
        state.points = [];
        var c = canvas.getContext('2d');
        c.clearRect(0, 0, canvas.width, canvas.height);
        return;
      }

      if (emptyEl) {
        emptyEl.classList.add('d-none');
      }

      var drawState = drawChart(canvas, rows, config);
      state.points = drawState ? drawState.points : [];
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

    canvas.addEventListener('mousemove', function (event) {
      if (!state.points.length) {
        hideTooltip(tooltip);
        return;
      }

      var rect = canvas.getBoundingClientRect();
      var x = event.clientX - rect.left;

      var nearest = state.points.reduce(function (best, point) {
        var dist = Math.abs(point.x - x);
        if (!best || dist < best.dist) {
          return { point: point, dist: dist };
        }
        return best;
      }, null);

      if (!nearest || nearest.dist > 28) {
        hideTooltip(tooltip);
        return;
      }

      var p = nearest.point;
      var html =
        '<strong>' + formatDateLabel(p.key) + '</strong><br>' +
        (config.revenueLabel || 'Revenue') + ': ' + formatCurrency(p.revenue, config.currency || 'EUR', 2, 2) + '<br>' +
        (config.salesLabel || 'Sales') + ': ' + p.conversions;

      showTooltip(tooltip, p.x, Math.min(p.yRevenue, p.yConversions), html);
    });

    canvas.addEventListener('mouseleave', function () {
      hideTooltip(tooltip);
    });

    window.addEventListener('resize', render);
    render();
  }

  function initAllCharts() {
    var configs = Array.isArray(window.mdfcforpsRevenueCharts) ? window.mdfcforpsRevenueCharts : [];
    configs.forEach(initChart);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllCharts);
  } else {
    initAllCharts();
  }
})();
