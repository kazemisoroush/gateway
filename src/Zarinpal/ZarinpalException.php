<?php

namespace Larabookir\Gateway\Zarinpal;

use Larabookir\Gateway\Exceptions\BankException;

class ZarinpalException extends BankException {

    public $errors = [
        - 1  => 'اطلاعات ارسال شده ناقص است.',
        - 2  => 'آدرس یا کد پذیرنده نادرست است.',
        - 3  => 'حداقل رقم پرداخت ۱۰۰۰ ریال است.',
        - 4  => 'برای این عملیات، سطح پذیرنده حداقل باید نقره‌ای باشد.',
        - 11 => 'درخواست مورد نظر پیدا نشد.',
        - 21 => 'هیچ نوع عملیات مالی برای این تراکنش یافت نشد.',
        - 22 => 'تراکنش ناموفق است.',
        - 33 => 'رقم تراکنش با رقم پرداخت شده مطابقت ندارد.',
        - 54 => 'درخواست مورد نظر بایگانی شده.',
        100  => 'عملیات با موفقیت انجام شد.',
        101  => 'عملیات پرداخت با موفقیت انجام شده ولی قبلا عملیات تایید پرداخت بر روی این تراکنش انجام شده است.',
    ];

    public function __construct($errorCode)
    {
        $code = intval($errorCode);

        parent::__construct($this->errors[$code], $errorCode);
    }
}
