# Branches (master / slave)

A chain with several branches runs one POS install per branch. One branch is the **master** (رئيسية) and owns the product catalogue; the others are **slaves** (فرع) that pull products, prices and recipes from it. A shop with a single install is **independent** (مستقلة).

## Configure

On every install: Settings «الإعدادات», tab «إدارة الفروع».

| Install | `node_type` | `master_node_link` | `web_api_key` |
|---|---|---|---|
| Master | `master` | — | the shared key |
| Each slave | `slave` | master POS base URL | **the same key as the master** |

`App\Services\BranchService::masterHttp()` sends the key as `X-Api-Key` on every call to the master.

## Pull from master

Admin page «إدارة الفروع» (`BranchManagement`, slaves only) compares local data with the master (`/api/check`, then the catalog endpoints) and offers:

| Action | What it does |
|---|---|
| تحديث البيانات | Refresh the comparison. |
| استيراد منتجات | Import products the slave does not have yet (by category). |
| تحديث الأسعار | Copy changed prices from the master. |
| تحديث الوصفات | Copy changed recipes (components). |

Products are matched by `product_ref`. The endpoints are listed in [Website integration API](/technical/website-integration#catalog-endpoints).
