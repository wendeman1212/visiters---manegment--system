<?php

namespace App\Models\Traits;

trait CompanyScoped
{
    public function getCompanyId()
    {
        return $this->company_id ?? null;
    }
}
