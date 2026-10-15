@extends('layouts.site', ['minimal' => true])

@section('title', __('layout.errors.503.title', [], 'ar').' | '.__('layout.errors.503.title', [], 'en'))

@section('content')
    @include('errors.partials.bilingual', ['status' => 503])
@endsection
