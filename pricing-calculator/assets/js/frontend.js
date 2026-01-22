function pricingCalculator(initialSections) {
    return {
        sections: initialSections.map(section => ({
            ...section,
            options: section.options.map(opt => ({ ...opt, checked: false }))
        })),
        email: '',
        hp_field: '',
        loading: false,
        message: '',
        currentStep: 0,
        isFinished: false,

        // Funkcje pomocnicze, aby uniknąć logiki w HTML (przez błędy cudzysłowów)
        isCurrentStep(idx) { return this.currentStep === idx && !this.isFinished; },
        canGoBack() { return this.currentStep > 0 || this.isFinished; },
        canGoForward() { return !this.isFinished; },
        showPdfBtn() { return this.message.includes('wysłana'); },
        getProgressStyle() {
            // Dodajemy +1 do currentStep, aby pierwszy krok był już widoczny jako "w trakcie"
            let stepsToFill = this.currentStep + 1;
            if (this.isFinished) stepsToFill = this.sections.length;
            
            let percent = (stepsToFill / this.sections.length) * 100;
            
            // Używamy koloru podstawowego (Fiolet: #6100B3) dla paska
            return `width: ${percent}%; background: #6100B3; height: 100%; transition: 0.4s;`;
        },

        handleSelection(sIndex, oIndex) {
            const section = this.sections[sIndex];
            if (section.type === 'radio') {
                section.options.forEach((opt, idx) => { opt.checked = (idx === oIndex); });
            } else {
                section.options[oIndex].checked = !section.options[oIndex].checked;
            }
        },

        get totalPrice() {
            let total = 0;
            this.sections.forEach(s => s.options.forEach(o => { if (o.checked) total += parseFloat(o.price || 0); }));
            return total.toFixed(2);
        },

        nextStep() {
            const curr = this.sections[this.currentStep];
            if (curr.required && !curr.options.some(o => o.checked)) {
                alert('Wybierz opcję!');
                return;
            }
            if (this.currentStep < this.sections.length - 1) { this.currentStep++; } 
            else { this.isFinished = true; }
        },

        prevStep() {
            if (this.isFinished) { this.isFinished = false; } 
            else if (this.currentStep > 0) { this.currentStep--; }
        },

        async send() {
            if (!this.email.includes('@')) { alert('Podaj email'); return; }
            this.loading = true;
            this.message = '';
            
            const selected = [];
            this.sections.forEach(s => s.options.forEach(o => {
                if (o.checked) selected.push(`${s.label}: ${o.label} (${o.price} zł)`);
            }));

            const formData = new FormData();
            formData.append('action', 'pc3_send');
            formData.append('nonce', PC3_AJAX.nonce);
            formData.append('email', this.email);
            formData.append('price', this.totalPrice);
            formData.append('summary', selected.join('\n'));
            formData.append('hp_field', this.hp_field);

            try {
                const r = await fetch(PC3_AJAX.url, { method: 'POST', body: formData });
                const res = await r.json();
                this.message = res.success ? 'Wycena została wysłana!' : 'Błąd: ' + res.data;
            } catch (e) {
                this.message = 'Błąd połączenia.';
            } finally {
                this.loading = false;
            }
        },

        generatePDF() {
            const element = document.createElement('div');
            
            // Zbieramy absolutnie wszystkie zaznaczone opcje ze wszystkich sekcji
            const allSelectedRows = [];
            this.sections.forEach(section => {
                const selectedOptionsInSubSection = section.options.filter(o => o.checked);
                if (selectedOptionsInSubSection.length > 0) {
                    allSelectedRows.push(`
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;">${section.label}</td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;">${selectedOptionsInSubSection.map(o => o.label).join(', ')}</td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">
                                ${selectedOptionsInSubSection.reduce((sum, o) => sum + parseFloat(o.price || 0), 0).toFixed(2)} zł
                            </td>
                        </tr>
                    `);
                }
            });

            // Pobieramy logo z lokalizacji (dodamy to w kroku 3)
            const logoHtml = PC3_AJAX.logo ? `<img src="${PC3_AJAX.logo}" style="max-height: 60px; margin-bottom: 20px;">` : '';

            element.innerHTML = `
                <div style="padding: 30px; font-family: 'Helvetica', sans-serif; color: #010A10;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px;">
                        <div>${logoHtml}</div>
                        <div style="text-align: right;">
                            <h1 style="color: #6100B3; margin: 0; font-size: 24px;">WYCENA</h1>
                            <p style="font-size: 12px; color: #4E4E4E;">Data: ${new Date().toLocaleDateString('pl-PL')}</p>
                        </div>
                    </div>

                    <p style="font-size: 14px; margin-bottom: 20px;"><strong>Klient:</strong> ${this.email}</p>

                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #6100B3; color: white;">
                                <th style="padding: 12px; text-align: left;">Kategoria</th>
                                <th style="padding: 12px; text-align: left;">Wybrane opcje</th>
                                <th style="padding: 12px; text-align: right;">Cena</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${allSelectedRows.join('')}
                        </tbody>
                    </table>

                    <div style="text-align: right; margin-top: 30px;">
                        <p style="margin: 0; font-size: 14px;">Suma całkowita:</p>
                        <h2 style="color: #02CB21; margin: 5px 0 0 0; font-size: 28px;">${this.totalPrice} zł</h2>
                    </div>

                    <div style="margin-top: 50px; border-top: 1px solid #eee; padding-top: 20px; font-size: 10px; color: #4E4E4E; text-align: center;">
                        <p>Dokument wygenerowany automatycznie przez system wycen. Zapraszamy do kontaktu w celu finalizacji.</p>
                    </div>
                </div>
            `;

            const opt = {
                margin: 0.5,
                filename: `wycena_${new Date().getTime()}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true }, // useCORS jest ważne przy obrazkach z innych domen (logo)
                jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().set(opt).from(element).save();
        }
    };
}