<template>
  <div ref="chart" class="admin-line-chart" role="img" :aria-label="ariaLabel" />
</template>

<script>
import * as echarts from 'echarts/core';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { LineChart } from 'echarts/charts';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([GridComponent, TooltipComponent, LineChart, CanvasRenderer]);

export default {
  name: 'AdminLineChart',
  props: {
    points: {
      type: Array,
      required: true,
    },
    ariaLabel: {
      type: String,
      default: '销售趋势图',
    },
    valueKey: {
      type: String,
      default: 'salesCents',
    },
    valueUnit: {
      type: String,
      default: 'money',
    },
  },
  watch: {
    points: {
      deep: true,
      handler() {
        this.renderChart();
      },
    },
  },
  mounted() {
    this.chart = echarts.init(this.$refs.chart);
    this.renderChart();
    window.addEventListener('resize', this.resizeChart);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.resizeChart);
    this.chart?.dispose();
  },
  methods: {
    renderChart() {
      if (!this.chart) {
        return;
      }
      this.chart.setOption({
        animationDuration: 300,
        grid: { top: 20, right: 18, bottom: 30, left: 54 },
        tooltip: {
          trigger: 'axis',
          valueFormatter: (value) => this.formatValue(value, true),
        },
        xAxis: {
          type: 'category',
          boundaryGap: false,
          data: this.points.map((item) => item.label),
          axisLine: { lineStyle: { color: '#dcdfe6' } },
          axisTick: { show: false },
          axisLabel: { color: '#909399', fontSize: 11 },
        },
        yAxis: {
          type: 'value',
          axisLabel: {
            color: '#909399',
            fontSize: 11,
            formatter: (value) => this.formatValue(value, false),
          },
          splitLine: { lineStyle: { color: '#f0f2f5' } },
        },
        series: [{
          type: 'line',
          data: this.points.map((item) => item[this.valueKey]),
          smooth: 0.25,
          symbol: 'circle',
          symbolSize: 7,
          lineStyle: { width: 2, color: '#2a7efb' },
          itemStyle: { color: '#2a7efb' },
          areaStyle: { color: 'rgba(42, 126, 251, 0.08)' },
        }],
      });
    },
    resizeChart() {
      this.chart?.resize();
    },
    formatValue(value, precise) {
      if (this.valueUnit === 'money') {
        return precise ? `¥${(value / 100).toFixed(2)}` : `¥${Math.round(value / 100)}`;
      }
      return `${value}${this.valueUnit}`;
    },
  },
};
</script>

<style scoped>
.admin-line-chart {
  width: 100%;
  height: 290px;
}
</style>
