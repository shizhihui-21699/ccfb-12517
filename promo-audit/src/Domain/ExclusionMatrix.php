<?php

declare(strict_types=1);

namespace PromoAudit\Domain;

/**
 * 互斥配置:规则两两互斥表。
 */
final class ExclusionMatrix
{
    /** @var array<string,array<string,true>> */
    private array $pairs = [];

    /** @param list<array{0:string,1:string}> $excludedPairs */
    public function __construct(array $excludedPairs)
    {
        foreach ($excludedPairs as [$a, $b]) {
            $this->pairs[$a][$b] = true;
            $this->pairs[$b][$a] = true;
        }
    }

    public function areExclusive(string $a, string $b): bool
    {
        return isset($this->pairs[$a][$b]);
    }

    /**
     * 检查一组规则 id 中是否存在互斥对;有则返回第一对 [a,b],无则 null。
     * @param list<string> $ruleIds
     * @return array{0:string,1:string}|null
     */
    public function firstViolation(array $ruleIds): ?array
    {
        $n = count($ruleIds);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->areExclusive($ruleIds[$i], $ruleIds[$j])) {
                    return [$ruleIds[$i], $ruleIds[$j]];
                }
            }
        }
        return null;
    }

    /**
     * 从候选集中剔除与已选集合互斥的规则,返回可用候选。用于搜索剪枝。
     * @param list<string> $chosen
     * @param list<string> $candidates
     * @return list<string>
     */
    public function filterCompatible(array $chosen, array $candidates): array
    {
        return array_values(array_filter($candidates, function (string $c) use ($chosen): bool {
            foreach ($chosen as $k) {
                if ($this->areExclusive($c, $k)) {
                    return false;
                }
            }
            return true;
        }));
    }
}
