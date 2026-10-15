@extends('layouts.site', ['minimal' => true])

@section('title', __('layout.errors.419.title', [], 'ar').' | '.__('layout.errors.419.title', [], 'en'))

@section('content')
    @include('errors.partials.bilingual', ['status' => 419])
@endsection
