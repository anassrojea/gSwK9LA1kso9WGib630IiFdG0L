<?php

// Phase 8C: vendor listings (photographers, kosha designers, bands…), featured spots, calligraphy logos.
return [

    'menu' => ['portal' => 'My vendor listing'],

    'cities' => [
        'damascus' => 'Damascus', 'aleppo' => 'Aleppo', 'homs' => 'Homs', 'latakia' => 'Latakia', 'hama' => 'Hama', 'tartus' => 'Tartus',
        'riyadh' => 'Riyadh', 'jeddah' => 'Jeddah', 'mecca' => 'Mecca', 'medina' => 'Medina', 'dammam' => 'Dammam', 'khobar' => 'Khobar',
    ],

    'categories' => [
        'photographer' => 'Photographer', 'kosha' => 'Kosha designer', 'band' => 'Band', 'cake' => 'Cake shop',
        'dress' => 'Dress shop', 'makeup' => 'Makeup artist', 'calligraphy' => 'Calligrapher',
    ],

    'categories_plural' => [
        'photographer' => 'Photographers', 'kosha' => 'Kosha designers', 'band' => 'Bands', 'cake' => 'Cake shops',
        'dress' => 'Dress shops', 'makeup' => 'Makeup artists', 'calligraphy' => 'Calligraphers',
    ],

    'statuses' => ['pending' => 'In review', 'approved' => 'Live', 'rejected' => 'Not approved', 'hidden' => 'Hidden'],

    'errors' => [
        'too_many_photos' => 'Up to :count photos.',
        'not_approved' => 'Your listing must be approved first.',
        'city' => 'Choose one of the cities you serve.',
        'no_calligraphy' => 'This vendor doesn’t offer name logos.',
    ],

    'public' => [
        'nav' => 'Vendors',
        'title' => 'Wedding & event vendors',
        'meta_description' => 'Photographers, kosha designers, bands, cake and dress shops, makeup artists and calligraphers for weddings and events in Syria and Saudi Arabia.',
        'intro' => 'Trusted vendors for your occasion. Message them directly on WhatsApp.',
        'city' => 'City',
        'category' => 'Category',
        'featured' => 'Featured',
        'count' => '{0} No vendors|{1} One vendor|[2,*] :count vendors',
        'empty' => 'No vendors here yet. Try another city or category.',
        'join_title' => 'Do you work with weddings and events?',
        'join_text' => 'List your business for free and reach hosts planning their occasion.',
        'join_cta' => 'List my business',
        'in_city' => 'in :city',
        'vendor_description' => ':name, :category, on Daawa.',
        'serves' => 'Serves :cities',
        'price_range' => 'Prices: :range',
        'whatsapp' => 'Message on WhatsApp',
        'whatsapp_message' => 'Hello :name, I found you on Daawa and I’m planning an occasion.',
        'photos' => 'Photos',
        'photo_alt' => ':name, photo :n',
        'about' => 'About :name',
        'no_description' => 'No description yet.',
        'occasions' => 'Occasions: :list',
    ],

    'card' => [
        'title' => 'Vendors for your occasion',
        'title_city' => 'Vendors near your venue in :city',
        'subtitle' => 'Photographers, kosha, bands and more. Contact goes straight to WhatsApp.',
        'dismiss' => 'Hide',
        'whatsapp' => 'WhatsApp',
        'all' => 'See all vendors',
    ],

    'feature' => [
        'title' => 'Featured spot',
        'text' => 'Shown first with a badge in the city you choose, for :days days.',
        'until' => 'Featured in :city until :date',
        'pitch' => 'Appear first in your city for 30 days: :price.',
        'buy' => 'Get featured',
    ],

    'calligraphy' => [
        'title' => 'Calligraphy name logo',
        'offer_title' => 'Your names in Arabic calligraphy',
        'offer_text' => 'A custom logo of your names, drawn by hand for your invitation and event: :prices.',
        'order' => 'Order a name logo',
        'names' => 'Names to write',
        'names_placeholder' => 'e.g. Reem & Omar',
        'brief' => 'Your brief',
        'brief_placeholder' => 'Style (Diwani, Thuluth…), colours, where you will use it…',
        'event' => 'For the event (optional)',
        'prices' => 'Name logo prices',
        'prices_help' => 'Hosts order and pay through Daawa; you see their brief below.',
        'requests' => 'Name logo requests',
        'no_requests' => 'No paid requests yet.',
        'start' => 'Start',
        'deliver' => 'Mark delivered',
        'statuses' => ['awaiting_payment' => 'Awaiting payment', 'new' => 'New', 'in_progress' => 'In progress', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'],
    ],

    'portal' => [
        'title' => 'My vendor listing',
        'intro' => 'Your public page on Daawa: what you do, where, and how hosts reach you.',
        'public_page' => 'My public page',
        'pending' => 'Your listing is being reviewed. We will email you once it is live.',
        'rejected' => 'Your listing was not approved:',
        'hidden' => 'Your listing is hidden:',
        'clicks' => 'Contact taps (30 days)',
        'clicks_whatsapp' => 'WhatsApp: :count',
        'clicks_instagram' => 'Instagram: :count',
        'name_ar' => 'Business name (Arabic)',
        'name_en' => 'Business name (English)',
        'cities' => 'Cities you serve',
        'occasions' => 'Occasions',
        'occasions_help' => 'None selected: every occasion.',
        'description_ar' => 'Description (Arabic)',
        'description_en' => 'Description (English)',
        'price_range' => 'Price range (optional)',
        'price_from' => 'From',
        'price_to' => 'To',
        'whatsapp' => 'WhatsApp number',
        'instagram' => 'Instagram',
        'save' => 'Save listing',
        'create' => 'Create my listing',
        'saved' => 'Listing saved.',
        'photos' => 'Photos (:count of :max)',
        'add_photo' => 'Add a photo',
        'no_photos' => 'Add photos of your work.',
        'remove_photo' => 'Remove photo',
        'photo_added' => 'Photo added.',
        'request_updated' => 'Request updated.',
    ],

    'admin' => [
        'label' => 'vendor',
        'plural' => 'Vendors',
        'name' => 'Vendor',
        'featured_until' => 'Featured until',
        'open' => 'Open page',
        'feature' => 'Feature',
        'days' => 'Days',
        'hide' => 'Hide',
    ],

];
