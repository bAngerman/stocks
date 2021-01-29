<template>
  <div>
    {{ message }}
  </div>
</template>

<script>

import axios from 'axios';

const default_layout = "default";

export default {
  computed: {},
  data() {
    return {
      message:'Hello World',
      tickers: null
    }
  },
  mounted() {
    let appUrl = document.querySelector('meta[name="app-url"]').content;

    axios
      .get(`${appUrl}/api/tickers`)
      .then(response => (this.tickers = response.data))
  }
};
</script>