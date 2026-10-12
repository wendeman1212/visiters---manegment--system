<?php

use App\Models\Branch;
use App\Models\Location;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/locations/{location}/branches', function (Location $location) {
    return $location->branches()->where('is_active', true)->get(['id', 'name']);
});

Route::get('/branches/{branch}/departments', function (Branch $branch) {
    return $branch->departments()->get(['id', 'name']);
});

Route::get('/departments/{department}/hosts', function (Department $department) {
    return $department->users()->where('role', 'host')->get(['id', 'name']);
});
