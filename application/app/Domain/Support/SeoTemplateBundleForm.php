<?php

namespace App\Domain\Support;

use App\Domain\Rules\SeoTemplateBundle;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;

final class SeoTemplateBundleForm
{
    /** @return array<string, mixed> */
    public static function fromRequest(Request $request, array $current = []): array
    {
        if ($current === []) {
            $current = SeoTemplateBundle::defaults();
        }
        $pages = array_keys(SeoTemplateBundle::labels());
        $rules = [
            'bundle_form' => 'required|array:page_templates',
            'bundle_form.page_templates' => 'required|array:'.implode(',', $pages),
        ];
        foreach ($pages as $page) {
            $rules['bundle_form.page_templates.'.$page] = 'required|array:managed_body_zone,slots,new_h2_name,new_h2_template';
        }
        $data = $request->validate($rules);
        $definition = $current;
        $definition['page_templates'] ??= [];
        foreach ($pages as $page) {
            $fields = $data['bundle_form']['page_templates'][$page];
            $input = [
                'template_form' => array_intersect_key($fields, array_flip(['managed_body_zone', 'slots'])),
                'new_h2_name' => $fields['new_h2_name'] ?? null,
                'new_h2_template' => $fields['new_h2_template'] ?? null,
            ];
            $pageRequest = $request->duplicate([], $input);
            if ($pageRequest->isJson()) {
                $pageRequest->setJson(new InputBag($input));
            }
            try {
                $definition['page_templates'][$page] = TemplateConfigurationForm::fromRequest($pageRequest, $current['page_templates'][$page] ?? []);
                SeoTemplateBundle::validatePage($definition['page_templates'][$page], $page);
            } catch (ValidationException $error) {
                $messages = [];
                foreach ($error->errors() as $key => $errors) {
                    $key = str_starts_with($key, 'template_form.') ? substr($key, strlen('template_form.')) : $key;
                    $messages['bundle_form.page_templates.'.$page.'.'.$key] = $errors;
                }
                throw ValidationException::withMessages($messages);
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['bundle_form.page_templates.'.$page.'.slots' => $error->getMessage()]);
            }
        }

        return $definition;
    }
}
