import requests
url = 'https://cdn.jsdelivr.net/npm/lightweight-charts@5.2.0/dist/lightweight-charts.standalone.production.js'
text = requests.get(url, timeout=10).text
print('status', 200)
for word in ['addSeries(','this.FM.addSeries','CandlestickSeries','addCustomSeries','this.FM.addSeries','addSeries=function','prototype.addSeries','function addSeries','addSeries:' ]:
    idx = text.find(word)
    print(f'--- {word} --- idx={idx}')
    if idx != -1:
        print(text[idx-100:idx+200])
        print('---')
