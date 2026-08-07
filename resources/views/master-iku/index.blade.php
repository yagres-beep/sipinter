@extends('layouts.app')

@section('title', 'Master IKU — SIPINTER')

@section('breadcrumb', 'Master IKU')

@section('content')
    <div x-data="{ tab: 'iku' }">
        <div class="subtabs">
            <button type="button" class="subtab" :class="tab === 'iku' ? 'on' : ''" @click="tab = 'iku'">📊 Master IKU</button>
            <button type="button" class="subtab" :class="tab === 'folder' ? 'on' : ''" @click="tab = 'folder'">📁 Struktur Folder</button>
            <button type="button" class="subtab" :class="tab === 'template' ? 'on' : ''" @click="tab = 'template'">🗎 Template Notula</button>
        </div>

        <div x-show="tab === 'iku'">
            <livewire:master-iku />
        </div>
        <div x-show="tab === 'folder'" x-cloak>
            <livewire:folder-config-manager />
        </div>
        <div x-show="tab === 'template'" x-cloak>
            <livewire:template-notula />
        </div>
    </div>
@endsection
