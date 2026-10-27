                <div class="mt-3">
                    <span class="text-xs font-semibold text-slate-500">Description</span>
                    <ul class="mt-1 space-y-1">
                        @foreach ($sectionDescriptionLines as $index => $line)
                            <li wire:key="description-line-{{ $index }}" class="flex items-center gap-2">
                                <span class="w-3 shrink-0 text-center text-slate-400">•</span>
                                <input
                                    wire:model.blur="sectionDescriptionLines.{{ $index }}"
                                    x-on:keydown.enter.prevent="$el.blur(); $wire.addDescriptionLine()"
                                    placeholder="Add a note"
                                    maxlength="{{ \App\Models\VmixSection::DescriptionLineMax }}"
                                    class="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-sm focus:border-amber-500 focus:outline-none"
                                >
                                <button
                                    type="button"
                                    wire:click="removeDescriptionLine({{ $index }})"
                                    title="Remove this note"
                                    class="rounded px-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                >&times;</button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-[11px] text-slate-400">One note per line. Not in the vMix master file.</p>
                </div>
