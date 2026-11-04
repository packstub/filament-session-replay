<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/** The lab's rows: enough of them, and lively enough, to measure what a busy table costs to record. */
class Order extends Model
{
    protected $table = 'lab_orders';

    protected $guarded = [];

    public static function seedLab(int $rows = 200): void
    {
        // LAB_ROWS=2000 composer serve: a longer table, for the memory lab's big pages (topped up, never cut).
        $rows = max($rows, (int) env('LAB_ROWS', 0));
        $have = static::query()->count();

        if ($have >= $rows) {
            return;
        }

        $names = ['Ada Lovelace', 'Grace Hopper', 'Alan Turing', 'Edsger Dijkstra', 'Barbara Liskov', 'Donald Knuth', 'Margaret Hamilton', 'Linus Torvalds'];
        $statuses = ['new', 'paid', 'packed', 'shipped', 'refunded'];

        foreach (range($have + 1, $rows) as $i) {
            static::query()->create([
                'reference' => 'ORD-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'customer' => $names[$i % count($names)],
                'status' => $statuses[$i % count($statuses)],
                'total' => 1500 + ($i * 37) % 90000,
            ]);
        }
    }
}
