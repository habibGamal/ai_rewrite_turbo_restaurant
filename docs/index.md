---
layout: home
title: Turbo POS
description: نظام نقطة بيع للمطاعم والكافيهات والمحلات مع لوحة إدارة ومخزون وتقارير.

hero:
  name: Turbo POS
  text: نظام نقطة بيع واحد للمطاعم والكافيهات والمحلات
  tagline: كاشير سريع، شاشة مطبخ، مخزون ومشتريات، تقارير مفصلة، وربط مع موقع الطلبات والمنصة المركزية.
  image:
    src: /logo.svg
    alt: Turbo POS
  actions:
    - theme: brand
      text: ابدأ من هنا
      link: /start/
    - theme: alt
      text: دليل الاستخدام
      link: /guide/cashier
    - theme: alt
      text: الإدارة
      link: /admin/products
---

<script setup>
import { computed } from 'vue'
import { useData } from 'vitepress'
import { VPFeatures } from 'vitepress/theme'

const { theme } = useData()

const allSections = [
  {
    title: 'البداية',
    details: 'نظرة عامة على النظام، أول تشغيل وتسجيل الدخول، وقاموس المصطلحات.',
    link: '/start/',
  },
  {
    title: 'دليل الاستخدام',
    details: 'العمل اليومي للكاشير: فتح وإغلاق الوردية، الطلبات، البيع المباشر، الدفع، المرتجعات، المطبخ، والملصقات.',
    link: '/guide/cashier',
  },
  {
    title: 'الإدارة',
    details: 'المنتجات والموديلات، أنواع الطلبات، المخزون والمشتريات، المصروفات، العملاء، التقارير، والإعدادات.',
    link: '/admin/products',
  },
  {
    title: 'التكامل',
    details: 'ربط موقع الطلبات الإلكتروني، المنصة المركزية، برنامج الطباعة Turbo Label، والمساعد الذكي.',
    link: '/integrations/website',
  },
  {
    title: 'Developer',
    details: 'Architecture, modules, domain events, API contracts, MCP server, and testing conventions.',
    link: '/dev/',
    privateOnly: true,
  },
  {
    title: 'Deployment and operations',
    details: 'Production deployment checklist, nginx/PHP-FPM configuration, and operational runbooks.',
    link: '/dev/ops/deployment',
    privateOnly: true,
  },
]

const visibleSections = computed(() =>
  allSections.filter((section) => !theme.value.isPublic || !section.privateOnly),
)
</script>

<VPFeatures :features="visibleSections" />

<div v-if="!theme.isPublic" style="margin-top: 2rem; text-align: center;">
  <p>للمطورين وفريق الدعم الفني: انتقل إلى <a href="/dev/">وثائق المطورين (Developer handbook)</a>.</p>
</div>
