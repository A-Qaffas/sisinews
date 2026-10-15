@extends('layouts.site', ['minimal' => true])

@section('title', __('layout.errors.403.title', [], 'ar').' | '.__('layout.errors.403.title', [], 'en'))

@section('content')
    @include('errors.partials.bilingual', ['status' => 403])
@endsection
