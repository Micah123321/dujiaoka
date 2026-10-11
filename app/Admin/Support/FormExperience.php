<?php

namespace App\Admin\Support;

use Dcat\Admin\Form;

class FormExperience
{
    public static function intro($form, string $title, string $description): void
    {
        $form->html('<div class="af-intro"><span class="af-eyebrow">填写指南</span><h2>' . e($title)
            . '</h2><p>' . e($description) . '</p><span class="af-required-note">带 * 的项目为必填，其余项目可按需设置。</span></div>')->plain();
    }

    public static function section($form, string $title, string $description): void
    {
        $form->html('<div class="af-section"><h3>' . e($title) . '</h3><p>' . e($description) . '</p></div>')->plain();
    }

    public static function guide(Form $form, string $kind): void
    {
        $form->html(function (Form $form) use ($kind) {
            $config = ['kind' => $kind, 'editing' => $form->isEditing()];
            if ($kind === 'payment') $config['catalog'] = PaymentFormCatalog::all($form->getKey());
            return view('admin.form-guide', ['config' => $config])->render();
        })->plain();
    }
}
