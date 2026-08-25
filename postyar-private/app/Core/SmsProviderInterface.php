<?php
namespace WHCM\Core;

interface SmsProviderInterface
{
    public function sendPattern(string $phone, string $template, array $parameters = []): array;
    public function test(string $phone): array;
}
