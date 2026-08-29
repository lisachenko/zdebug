<?php
class ProbeService
{
    public function handle(int $value): int
    {
        $doubled = $value * 2;
        try { if ($value > 100) { throw new RuntimeException('nope'); } } catch (RuntimeException) {}
        return $doubled;
    }
}
function probeHelper(int $value): int
{
    return $value + 1;
}
$service = new ProbeService();
$total   = 0;
foreach ([1, 2] as $value) {
    $total += $service->handle($value);
}
$total += probeHelper(5);
$total += $service->handle(101);
echo "PAYLOAD TOTAL={$total}\n";
