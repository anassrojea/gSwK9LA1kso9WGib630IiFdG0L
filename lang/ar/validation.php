<?php

// Rules not listed here fall back to English (APP_FALLBACK_LOCALE).
return [
    'accepted' => 'يجب قبول :attribute.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'between' => [
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفاً.',
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute نعم أو لا.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخاً صحيحاً.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'exists' => ':attribute المختار غير صالح.',
    'in' => ':attribute المختار غير صالح.',
    'integer' => 'يجب أن يكون :attribute عدداً صحيحاً.',
    'lte' => [
        'numeric' => 'يجب ألا تتجاوز قيمة :attribute :value.',
    ],
    'max' => [
        'numeric' => 'يجب ألا تتجاوز قيمة :attribute :max.',
        'string' => 'يجب ألا يتجاوز طول :attribute :max حرفاً.',
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عناصر.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :max كيلوبايت.',
    ],
    'min' => [
        'numeric' => 'يجب أن تكون قيمة :attribute :min على الأقل.',
        'string' => 'يجب أن يكون طول :attribute :min أحرف على الأقل.',
        'array' => 'يجب أن يحتوي :attribute على :min عناصر على الأقل.',
    ],
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute في تسريب بيانات، اختاروا غيرها.',
    ],
    'prohibited' => 'حقل :attribute غير مسموح.',
    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other :value.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => ':attribute مستخدم من قبل.',
    'url' => 'يجب أن يكون :attribute رابطاً صحيحاً.',

    'custom' => [],

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'attending' => 'الحضور',
        'people_count' => 'عدد الحضور',
        'kids_count' => 'عدد الأطفال',
        'message' => 'التهنئة',
        'allergies' => 'الحساسية',
        'config' => 'إعدادات الدعوة',
        'tables' => 'عدد الطاولات',
        'chairs' => 'عدد الكراسي',
    ],
];
