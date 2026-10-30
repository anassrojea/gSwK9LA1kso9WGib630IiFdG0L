<?php

namespace App\Services\Seating;

use App\Models\HallLayout;
use App\Services\HallGenerator;

/**
 * Starter halls (phase 8B) that every venue can copy into its own halls and
 * change in the designer: a ballroom with 20 round tables, a banquet hall with
 * long tables (men and women apart, a VIP row) and a garden with mixed
 * seating (round tables, banquet tables, sofas, a kids table, a buffet).
 * Stored as hall_layouts rows with a template_key and no owner.
 */
class HallTemplates
{
    public const KEYS = ['ballroom', 'banquet', 'garden'];

    public function __construct(private HallGenerator $generator) {}

    /**
     * @return array<string, array{name: string, layout: array}>
     */
    public function all(): array
    {
        return [
            'ballroom' => ['name' => 'Ballroom · 20 round tables', 'layout' => $this->ballroom()],
            'banquet' => ['name' => 'Banquet hall', 'layout' => $this->banquet()],
            'garden' => ['name' => 'Garden · mixed seating', 'layout' => $this->garden()],
        ];
    }

    /** Create or refresh the starter halls. */
    public function seed(): void
    {
        foreach ($this->all() as $key => $template) {
            HallLayout::query()->updateOrCreate(
                ['template_key' => $key],
                ['name' => $template['name'], 'layout' => $template['layout'], 'org_id' => null, 'owner_id' => null],
            );
        }
    }

    public function ballroom(): array
    {
        return HallLayoutData::normalize($this->generator->generate(20, 10, 'mixed', 'round', 'Ballroom', 'tpl_ballroom'));
    }

    public function banquet(): array
    {
        $layout = $this->generator->generate(12, 12, 'split', 'rect', 'Banquet hall', 'tpl_banquet');
        // the families' VIP row in front of the kosha
        $layout['sections'][] = ['id' => 'vip', 'label' => 'VIP', 'area' => ['x' => 200, 'y' => 92, 'w' => 400, 'h' => 50]];
        $layout['tables'][] = ['id' => 'T13', 'section' => 'vip', 'shape' => 'row', 'x' => 400, 'y' => 120, 'seats' => 16, 'w' => 336, 'h' => 18, 'rotation' => 0, 'chairR' => 8];
        unset($layout['danceFloor']);
        $layout['obstacles'] = [
            ['id' => 'O1', 'kind' => 'buffet', 'x' => 24, 'y' => 30, 'w' => 150, 'h' => 40],
            ['id' => 'O2', 'kind' => 'buffet', 'x' => 626, 'y' => 30, 'w' => 150, 'h' => 40],
        ];

        return HallLayoutData::normalize($layout);
    }

    public function garden(): array
    {
        $tables = [];
        $n = 0;
        $next = function (array $t) use (&$tables, &$n) {
            $n++;
            $tables[] = ['id' => 'T'.$n] + $t;
        };

        // a VIP row facing the kosha
        $next(['section' => 'vip', 'shape' => 'row', 'x' => 450, 'y' => 128, 'seats' => 12, 'w' => 252, 'h' => 18, 'rotation' => 0, 'chairR' => 8]);
        // two rows of round tables
        foreach ([[250, 230], [450, 230], [650, 230], [250, 350], [450, 350], [650, 350]] as [$x, $y]) {
            $next(['section' => 'mixed', 'shape' => 'round', 'x' => $x, 'y' => $y, 'seats' => 8, 'r' => 24, 'chairR' => 8, 'ring' => 38]);
        }
        // long family tables at the back
        foreach ([300, 600] as $x) {
            $next(['section' => 'mixed', 'shape' => 'rect', 'x' => $x, 'y' => 470, 'seats' => 12, 'w' => 200, 'h' => 30, 'rotation' => 0, 'sides' => 'both', 'heads' => false, 'chairR' => 8]);
        }
        // lounges along the sides
        foreach ([[60, 250], [60, 380], [840, 250], [840, 380]] as [$x, $y]) {
            $next(['section' => 'mixed', 'shape' => 'sofa', 'x' => $x, 'y' => $y, 'seats' => 3, 'w' => 90, 'h' => 32, 'rotation' => 90, 'chairR' => 10, 'label' => 'Lounge']);
        }
        // the children's table near the entrance
        $next(['section' => 'mixed', 'shape' => 'round', 'x' => 450, 'y' => 560, 'seats' => 10, 'r' => 22, 'chairR' => 7, 'ring' => 36, 'kids' => true, 'label' => 'Kids']);

        return HallLayoutData::normalize([
            'id' => 'tpl_garden',
            'name' => 'Garden',
            'size' => ['w' => 900, 'h' => 640],
            'stage' => ['x' => 330, 'y' => 20, 'w' => 240, 'h' => 64, 'label' => 'Kosha'],
            'sections' => [
                ['id' => 'mixed', 'label' => 'Guests', 'area' => ['x' => 16, 'y' => 170, 'w' => 868, 'h' => 440]],
                ['id' => 'vip', 'label' => 'VIP', 'area' => ['x' => 300, 'y' => 98, 'w' => 300, 'h' => 56]],
            ],
            'entrances' => [['x' => 410, 'y' => 626, 'w' => 80], ['x' => 886, 'y' => 470, 'w' => 70, 'rotation' => 90]],
            'obstacles' => [
                ['id' => 'O1', 'kind' => 'buffet', 'x' => 24, 'y' => 24, 'w' => 200, 'h' => 44],
                ['id' => 'O2', 'kind' => 'column', 'x' => 140, 'y' => 520, 'w' => 26, 'h' => 26, 'label' => ''],
                ['id' => 'O3', 'kind' => 'column', 'x' => 734, 'y' => 520, 'w' => 26, 'h' => 26],
            ],
            'labels' => [
                ['id' => 'L1', 'text' => 'Garden', 'x' => 760, 'y' => 46, 'size' => 18],
            ],
            'tables' => $tables,
        ]);
    }
}
