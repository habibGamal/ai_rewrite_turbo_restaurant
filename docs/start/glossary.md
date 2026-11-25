---
title: قاموس المصطلحات
description: مرجع سريع للمصطلحات المستخدمة في شاشات النظام لأصحاب المحلات والمديرين والكاشير.
---

# قاموس المصطلحات

يوضح هذا القاموس معنى كل مصطلح يظهر في شاشات نقطة البيع ولوحة الإدارة لأصحاب المحلات والمديرين والكاشير.

<script setup>
import { data } from './glossary.data'
</script>

<table>
  <thead>
    <tr>
      <th>المصطلح</th>
      <th>المعنى</th>
    </tr>
  </thead>
  <tbody>
    <tr v-for="entry in data.entries" :key="entry.term">
      <td><strong>{{ entry.term }}</strong></td>
      <td>{{ entry.meaning }}</td>
    </tr>
  </tbody>
</table>
