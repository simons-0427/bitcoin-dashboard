<?php

declare(strict_types=1);

function bitcoin_core_calendar_years(string $date, int $years): string
{
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    $year += $years;
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'));
    return sprintf('%04d-%02d-%02d', $year, $month, min($day, (int) $first->format('t')));
}

function bitcoin_core_blocks_until_halving(int $height): int
{
    if ($height < 0) throw new InvalidArgumentException('Blockhöhe muss nichtnegativ sein.');
    return (intdiv($height, 210000) + 1) * 210000 - $height;
}
