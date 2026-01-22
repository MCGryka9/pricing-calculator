<?php
$sections_json = wp_json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$unique_id = 'pc_' . uniqid();
?>

<script>
    window.<?php echo $unique_id; ?>_data = <?php echo $sections_json; ?>;
</script>

<div class="pc3-wrap" x-data="pricingCalculator(window.<?php echo $unique_id; ?>_data)" x-cloak>
    
    <div class="pc3-step-info">
        Krok <strong x-text="currentStep + 1"></strong> z <span x-text="sections.length"></span>
    </div>
    <div class="pc3-progress-bar" style="background: #eee; height: 8px; border-radius: 4px; margin-bottom: 30px; overflow: hidden;">
        <div :style="getProgressStyle()"></div>
    </div>

    <template x-for="(section, sIndex) in sections" :key="section.id">
        <div x-show="isCurrentStep(sIndex)">
            <h3 x-text="section.label"></h3>
            <div class="pc3-options-grid">
                <template x-for="(opt, oIndex) in section.options" :key="opt.id">
                    <label class="pc3-option" :class="opt.checked ? 'is-selected' : ''">
                        <input 
                            :type="section.type" 
                            @change="handleSelection(sIndex, oIndex)"
                            :checked="opt.checked">
                        <span x-text="opt.label"></span>
                        <!-- Tooltip tylko jeśli jest adnotacja -->
                        <template x-if="opt.tooltip_enabled && opt.note && opt.note.trim() !== ''">
                    <span class="pc3-tooltip"> ? <span class="pc3-tooltip-text" x-text="opt.note"></span> </span> </template>
                    <span class="pc3-price-tag">+ <span x-text="opt.price"></span> zł</span>
                    </label>
                </template>

                <div class="pc3-section-note" x-show="section.note_enabled && section.note && section.note.trim() !== ''"> <span x-text="section.note"></span>
                </div>

            </div>
        </div>
    </template>

    <div x-show="isFinished">
        <h3 style="text-align: center;">Twoje zestawienie</h3>
        <div class="pc3-summary-box">
            <template x-for="section in sections">
                <template x-for="opt in section.options">
                    <div x-show="opt.checked" style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span x-text="section.label + ': ' + opt.label"></span>
                        <span x-text="opt.price + ' zł'"></span>
                    </div>
                </template>
            </template>
            <hr>
            <div class="pc3-total-final">Suma: <span x-text="totalPrice"></span> zł</div>
        </div>

        <div class="pc3-form">
            <div style="display:none"><input type="text" x-model="hp_field"></div>
            <input type="email" x-model="email" placeholder="Twój adres e-mail" class="pc3-input">
            <button type="button" @click="send()" :disabled="loading" class="pc3-btn-send">
                <span x-show="!loading">Wyślij ofertę na e-mail</span>
                <span x-show="loading">Wysyłanie...</span>
            </button>
            <p x-show="message" x-text="message" class="pc3-msg"></p>
            <button x-show="showPdfBtn()" @click="generatePDF()" type="button" class="pc3-btn-pdf">
                Pobierz wycenę (PDF)
            </button>
        </div>
    </div>

    <div class="pc3-nav-container">
        <button type="button" @click="prevStep()" x-show="canGoBack()" class="pc3-btn-prev">Wstecz</button>
        <button type="button" @click="nextStep()" x-show="canGoForward()" class="pc3-btn-next">Dalej</button>
    </div>
</div>