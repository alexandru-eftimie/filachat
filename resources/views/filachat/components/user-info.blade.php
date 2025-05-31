@php
$column = config('filachat.user_info_column');
@endphp

@if($column)
    {{ $user->$column }}
@endif
