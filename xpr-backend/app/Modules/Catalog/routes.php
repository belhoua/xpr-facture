<?php

declare(strict_types=1);

use App\Modules\Catalog\Controllers\CategoryArchiveController;
use App\Modules\Catalog\Controllers\CategoryListController;
use App\Modules\Catalog\Controllers\CategoryStoreController;
use App\Modules\Catalog\Controllers\CategoryUpdateController;
use App\Modules\Catalog\Controllers\ProductArchiveController;
use App\Modules\Catalog\Controllers\ProductListController;
use App\Modules\Catalog\Controllers\ProductShowController;
use App\Modules\Catalog\Controllers\ProductStoreController;
use App\Modules\Catalog\Controllers\ProductUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par CatalogServiceProvider. Chaque route porte sa permission,
// LECTURE COMPRISE (§10).
//
// {product} et {category} sont de simples paramètres de route, PAS des
// bindings de modèle : SubstituteBindings s'exécute avant `tenant` et
// résoudrait le modèle hors scope de société
// (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('categories', CategoryListController::class)
            ->middleware('permission:'.Permission::CatalogView->value);
        Route::post('categories', CategoryStoreController::class)
            ->middleware('permission:'.Permission::CatalogCreate->value);
        Route::patch('categories/{category}', CategoryUpdateController::class)
            ->middleware('permission:'.Permission::CatalogUpdate->value);
        // Archivage et non suppression : les produits de la catégorie restent
        // au catalogue, et les documents émis restent lisibles.
        Route::delete('categories/{category}', CategoryArchiveController::class)
            ->middleware('permission:'.Permission::CatalogDelete->value);

        Route::get('products', ProductListController::class)
            ->middleware('permission:'.Permission::CatalogView->value);
        Route::get('products/{product}', ProductShowController::class)
            ->middleware('permission:'.Permission::CatalogView->value);
        Route::post('products', ProductStoreController::class)
            ->middleware('permission:'.Permission::CatalogCreate->value);
        Route::patch('products/{product}', ProductUpdateController::class)
            ->middleware('permission:'.Permission::CatalogUpdate->value);
        Route::delete('products/{product}', ProductArchiveController::class)
            ->middleware('permission:'.Permission::CatalogDelete->value);
    });
