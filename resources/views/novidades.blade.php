<x-layouts::app>
    {{-- Fonte única: CHANGELOG.md na raiz do projeto (o mesmo arquivo versionado no git). --}}
    <div class="glass-panel animate-rise mx-auto max-w-3xl rounded-3xl p-6 sm:p-8">
        <p class="eyebrow">Mr. Bills</p>

        <article class="changelog mt-2 text-sm leading-relaxed text-foreground
                        [&_h1]:text-2xl [&_h1]:font-bold [&_h1]:mb-6
                        [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:text-lg [&_h2]:font-bold [&_h2:first-of-type]:mt-0
                        [&_h3]:mt-4 [&_h3]:mb-2 [&_h3]:eyebrow
                        [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:ps-5
                        [&_strong]:font-semibold">
            {!! str(file_get_contents(base_path('CHANGELOG.md')))->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </article>
    </div>
</x-layouts::app>
