<?php

namespace App\Services\Payments\Items;

/**
 * Order line "highlight_video" (plan section 14.5): grants the highlight_video
 * feature; the host then builds the video from the album (App\Jobs\BuildHighlightVideo).
 */
class HighlightVideoItem extends FeatureItem {}
