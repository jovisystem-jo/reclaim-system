package com.reclaim.mobile.screens.fragments

import android.content.Intent
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ProgressBar
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.screens.activities.ItemDetailActivity
import com.reclaim.mobile.screens.activities.MainActivity
import com.reclaim.mobile.screens.adapters.ItemAdapter
import kotlinx.coroutines.launch

class ItemsFragment : Fragment() {
    private var scope: String = "public"
    private lateinit var repository: MobileRepository
    private lateinit var adapter: ItemAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        scope = arguments?.getString(ARG_SCOPE) ?: "public"
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_items, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        repository = MobileRepository(requireContext())

        val publicButton: MaterialButton = view.findViewById(R.id.buttonScopePublic)
        val mineButton: MaterialButton = view.findViewById(R.id.buttonScopeMine)
        val reportButton: View = view.findViewById(R.id.buttonOpenReportItem)
        val search: TextInputEditText = view.findViewById(R.id.editItemSearch)
        val progress: ProgressBar = view.findViewById(R.id.progressItems)
        val empty: TextView = view.findViewById(R.id.textItemsEmpty)
        val recycler: RecyclerView = view.findViewById(R.id.recyclerItems)
        val swipe: SwipeRefreshLayout = view.findViewById(R.id.swipeItems)

        adapter = ItemAdapter { item ->
            startActivity(Intent(requireContext(), ItemDetailActivity::class.java).putExtra(ItemDetailActivity.EXTRA_ITEM_ID, item.id))
        }

        recycler.layoutManager = LinearLayoutManager(requireContext())
        recycler.adapter = adapter

        fun renderScope() {
            publicButton.isChecked = scope == "public"
            mineButton.isChecked = scope == "mine"
        }

        fun loadItems() {
            progress.visibility = View.VISIBLE
            lifecycleScope.launch {
                repository.items(scope, search.text?.toString()?.trim().orEmpty(), null)
                    .onSuccess { response ->
                        adapter.submitList(response.items)
                        empty.visibility = if (response.items.isEmpty()) View.VISIBLE else View.GONE
                        empty.text = getString(R.string.empty_state)
                    }
                    .onFailure {
                        empty.visibility = View.VISIBLE
                        empty.text = ApiErrorParser.message(it)
                    }

                progress.visibility = View.GONE
                swipe.isRefreshing = false
            }
        }

        publicButton.setOnClickListener {
            scope = "public"
            renderScope()
            loadItems()
        }

        mineButton.setOnClickListener {
            scope = "mine"
            renderScope()
            loadItems()
        }

        reportButton.setOnClickListener {
            (activity as? MainActivity)?.openReportItem()
        }

        search.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) = Unit
            override fun afterTextChanged(s: Editable?) {
                loadItems()
            }
        })

        swipe.setOnRefreshListener { loadItems() }

        renderScope()
        loadItems()
    }

    override fun onResume() {
        super.onResume()
        view?.findViewById<SwipeRefreshLayout>(R.id.swipeItems)?.isRefreshing = true
        view?.post {
            view?.findViewById<SwipeRefreshLayout>(R.id.swipeItems)?.isRefreshing = false
        }
    }

    companion object {
        private const val ARG_SCOPE = "scope"

        fun newInstance(scope: String): ItemsFragment {
            return ItemsFragment().apply {
                arguments = Bundle().apply {
                    putString(ARG_SCOPE, scope)
                }
            }
        }
    }
}
