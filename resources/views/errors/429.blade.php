@extends('layouts.site', ['minimal' => true])

@section('title', __('layout.errors.429.title', [], 'ar').' | '.__('layout.errors.429.title', [], 'en'))

@section('content')
    @include('errors.partials.bilingual', ['status' => 429])
@endsection
