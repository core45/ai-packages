<div
    x-data="{
        title: '',
        description: '',
        url: '',
        mode: 'desktop',
        titleStatePath: '{{ $getTitleStatePath() }}',
        descriptionStatePath: '{{ $getDescriptionStatePath() }}',
        urlStatePath: '{{ $getUrlStatePath() }}',

        init() {
            this.readState();

            if (this.titleStatePath) {
                this.$watch('$wire.data.' + this.titleStatePath, () => this.readState());
            }
            if (this.descriptionStatePath) {
                this.$watch('$wire.data.' + this.descriptionStatePath, () => this.readState());
            }
            if (this.urlStatePath) {
                this.$watch('$wire.data.' + this.urlStatePath, () => this.readState());
            }
        },

        readState() {
            this.title = this.getNestedValue(this.$wire.data, this.titleStatePath) || '';
            this.description = this.getNestedValue(this.$wire.data, this.descriptionStatePath) || '';
            this.url = this.getNestedValue(this.$wire.data, this.urlStatePath) || '';
        },

        getNestedValue(obj, path) {
            if (!path) return '';
            return path.split('.').reduce((acc, key) => (acc && acc[key] !== undefined) ? acc[key] : '', obj);
        },

        get truncatedTitle() {
            if (!this.title) return '{{ __('Page Title') }}';
            return this.title.length > 60 ? this.title.substring(0, 60) + '...' : this.title;
        },

        get truncatedDescription() {
            if (!this.description) return '{{ __('Meta description will appear here...') }}';
            return this.description.length > 156 ? this.description.substring(0, 156) + '...' : this.description;
        },

        get displayUrl() {
            if (!this.url) return 'example.com';
            try {
                let url = this.url;
                if (!url.startsWith('http')) {
                    url = 'https://' + url;
                }
                let parsed = new URL(url);
                let path = parsed.pathname === '/' ? '' : ' › ' + parsed.pathname.split('/').filter(Boolean).join(' › ');
                return parsed.hostname + path;
            } catch (e) {
                return this.url;
            }
        },

        get titleLength() {
            return this.title.length;
        },

        get descriptionLength() {
            return this.description.length;
        },

        titleColor() {
            let len = this.titleLength;
            if (len >= 50 && len <= 60) return '#16a34a';
            if ((len >= 40 && len < 50) || (len > 60 && len <= 65)) return '#ea580c';
            return '#dc2626';
        },

        descriptionColor() {
            let len = this.descriptionLength;
            if (len >= 120 && len <= 156) return '#16a34a';
            if ((len >= 80 && len < 120) || (len > 156 && len <= 170)) return '#ea580c';
            return '#dc2626';
        }
    }"
    style="margin-top: 4px;"
>
    {{-- Section header --}}
    <div style="margin-bottom: 12px;">
        <h4 style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 4px 0;">
            {{ __('SERP Preview') }}
        </h4>
        <p style="font-size: 12px; color: #6b7280; margin: 0;">
            {{ __('Preview how this page will appear in Google search results.') }}
        </p>
    </div>

    {{-- Desktop/Mobile toggle --}}
    <div style="display: flex; gap: 0; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb;">
        <button
            type="button"
            @click="mode = 'desktop'"
            :style="mode === 'desktop'
                ? 'padding: 6px 16px; font-size: 13px; font-weight: 500; border: none; background: none; cursor: pointer; border-bottom: 2px solid #2563eb; color: #2563eb; margin-bottom: -2px;'
                : 'padding: 6px 16px; font-size: 13px; font-weight: 500; border: none; background: none; cursor: pointer; border-bottom: 2px solid transparent; color: #6b7280; margin-bottom: -2px;'"
        >
            {{ __('Desktop') }}
        </button>
        <button
            type="button"
            @click="mode = 'mobile'"
            :style="mode === 'mobile'
                ? 'padding: 6px 16px; font-size: 13px; font-weight: 500; border: none; background: none; cursor: pointer; border-bottom: 2px solid #2563eb; color: #2563eb; margin-bottom: -2px;'
                : 'padding: 6px 16px; font-size: 13px; font-weight: 500; border: none; background: none; cursor: pointer; border-bottom: 2px solid transparent; color: #6b7280; margin-bottom: -2px;'"
        >
            {{ __('Mobile') }}
        </button>
    </div>

    {{-- SERP Preview card --}}
    <div
        :style="mode === 'mobile'
            ? 'max-width: 400px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; font-family: Arial, sans-serif;'
            : 'background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; font-family: Arial, sans-serif;'"
    >
        {{-- URL breadcrumb --}}
        <div
            :style="mode === 'mobile'
                ? 'font-size: 12px; color: #006621; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;'
                : 'font-size: 14px; color: #006621; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;'"
            x-text="displayUrl"
        ></div>

        {{-- Title --}}
        <div
            :style="mode === 'mobile'
                ? 'font-size: 18px; color: #1a0dab; margin-bottom: 4px; line-height: 1.3; cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'
                : 'font-size: 20px; color: #1a0dab; margin-bottom: 4px; line-height: 1.3; cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'"
            x-text="truncatedTitle"
        ></div>

        {{-- Description --}}
        <div
            :style="mode === 'mobile'
                ? 'font-size: 13px; color: #545454; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;'
                : 'font-size: 14px; color: #545454; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;'"
            x-text="truncatedDescription"
        ></div>
    </div>

    {{-- Character counts --}}
    <div style="margin-top: 10px; display: flex; gap: 24px; flex-wrap: wrap;">
        <div style="font-size: 12px;">
            <span style="color: #6b7280;">{{ __('Title') }}:</span>
            <span :style="'font-weight: 600; color: ' + titleColor()" x-text="titleLength + '/60 {{ __('characters') }}'"></span>
        </div>
        <div style="font-size: 12px;">
            <span style="color: #6b7280;">{{ __('Description') }}:</span>
            <span :style="'font-weight: 600; color: ' + descriptionColor()" x-text="descriptionLength + '/160 {{ __('characters') }}'"></span>
        </div>
    </div>
</div>
