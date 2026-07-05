<?php

namespace App\Message;

/** Orden de generación de un informe ya registrado en la tabla report. */
final readonly class GenerateReport
{
    public function __construct(public int $reportId)
    {
    }
}
