{{-- "Started 14 October, 10:30 PM": $key is the lang key (started|ended), $moment comes from LiveBroadcasts (Cairo time). --}}
<time datetime="{{ $moment['iso'] }}">{{ __('live.'.$key, ['when' => __('live.when', ['date' => $moment['date'], 'time' => $moment['time']])]) }}</time>
