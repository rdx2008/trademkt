/*
 * Gráficos do painel e do BI (ApexCharts, que acompanha o tema).
 *
 * Uso: <div data-grafico='{"tipo":"coluna","categorias":["a","b"],"series":[{"nome":"X","dados":[1,2]}]}'></div>
 *
 * Tipos: coluna, barra (horizontal), linha, area, rosca, radial.
 * Opções: altura, cores, empilhado, sufixo (ex.: "%"), formato ("inteiro"|"decimal"), semLegenda.
 * O modo escuro (atributo data-theme-version no body) é respeitado e os gráficos se redesenham
 * quando o usuário alterna o tema.
 */
(function () {
  'use strict';

  var CORES = ['#222fb9', '#27ae60', '#f5a623', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'];
  var graficos = [];

  function escuro() {
    return document.body.getAttribute('data-theme-version') === 'dark';
  }

  function numero(valor, cfg) {
    if (valor === null || valor === undefined) { return '—'; }
    var casas = cfg.formato === 'decimal' ? 1 : 0;
    return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }) + (cfg.sufixo || '');
  }

  function opcoes(cfg) {
    var tipo = cfg.tipo || 'coluna';
    var series = (cfg.series || []).map(function (s) { return { name: s.nome, data: s.dados }; });
    var base = {
      chart: {
        fontFamily: 'Poppins, sans-serif',
        height: cfg.altura || 300,
        toolbar: { show: false },
        background: 'transparent',
        foreColor: escuro() ? '#9aa0a8' : '#6e6e6e',
        stacked: !!cfg.empilhado,
        animations: { enabled: true, speed: 500 }
      },
      theme: { mode: escuro() ? 'dark' : 'light' },
      colors: cfg.cores || CORES,
      dataLabels: { enabled: false },
      grid: { borderColor: escuro() ? '#3b3f45' : '#ececec', strokeDashArray: 4 },
      legend: { show: !cfg.semLegenda, position: 'bottom', fontSize: '12px', markers: { radius: 12 } },
      noData: { text: 'Sem dados no período' },
      tooltip: { theme: escuro() ? 'dark' : 'light', y: { formatter: function (v) { return numero(v, cfg); } } },
      stroke: { width: 0 }
    };

    if (tipo === 'rosca') {
      base.chart.type = 'donut';
      base.series = (cfg.series && cfg.series[0] ? cfg.series[0].dados : []).map(Number);
      base.labels = cfg.categorias || [];
      base.stroke = { width: 2, colors: [escuro() ? '#1b1b1b' : '#fff'] };
      base.plotOptions = { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: cfg.rotuloTotal || 'Total', formatter: function (w) { return numero(w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0), { formato: 'inteiro' }); } } } } } };
      return base;
    }

    if (tipo === 'radial') {
      base.chart.type = 'radialBar';
      base.series = (cfg.series && cfg.series[0] ? cfg.series[0].dados : []).map(Number);
      base.labels = cfg.categorias || [];
      base.plotOptions = { radialBar: { hollow: { size: '58%' }, dataLabels: { value: { fontSize: '1.6rem', formatter: function (v) { return numero(v, cfg); } } } } };
      return base;
    }

    base.series = series;
    base.xaxis = { categories: cfg.categorias || [], labels: { rotate: 0, trim: true }, axisBorder: { show: false }, axisTicks: { show: false } };
    base.yaxis = { labels: { formatter: function (v) { return numero(v, cfg); } } };

    if (tipo === 'coluna') {
      base.chart.type = 'bar';
      base.plotOptions = { bar: { borderRadius: 4, columnWidth: '45%' } };
    } else if (tipo === 'barra') {
      base.chart.type = 'bar';
      base.plotOptions = { bar: { horizontal: true, borderRadius: 4, barHeight: '55%' } };
      base.yaxis = { labels: { maxWidth: 180 } };
      base.xaxis.labels = { formatter: function (v) { return numero(v, cfg); } };
    } else if (tipo === 'linha') {
      base.chart.type = 'line';
      base.stroke = { width: 3, curve: 'smooth' };
      base.markers = { size: 4 };
    } else {
      base.chart.type = 'area';
      base.stroke = { width: 2, curve: 'smooth' };
      base.fill = { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 95, 100] } };
    }

    return base;
  }

  function montar(el) {
    var cfg;
    try { cfg = JSON.parse(el.getAttribute('data-grafico')); } catch (e) { return; }
    if (typeof ApexCharts === 'undefined') { return; }
    var chart = new ApexCharts(el, opcoes(cfg));
    chart.render();
    graficos.push({ el: el, cfg: cfg, chart: chart });
  }

  function iniciar() {
    document.querySelectorAll('[data-grafico]').forEach(montar);
  }

  window.addEventListener('tema-alterado', function () {
    graficos.forEach(function (g) { g.chart.updateOptions(opcoes(g.cfg), true, false); });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
