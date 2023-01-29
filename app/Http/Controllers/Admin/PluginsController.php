<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class PluginsController extends Controller
{
    /**
     * @return Application|Factory|View
     */
    public function plugins(): View|Factory|Application
    {
        $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
        ];

        $breadcrumbs = [
                ['link' => url(config('app.admin_path')."/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['name' => __('locale.menu.Plugins')],
        ];


        return view('admin.Plugins.index', [
                'pageConfigs' => $pageConfigs,
                'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
