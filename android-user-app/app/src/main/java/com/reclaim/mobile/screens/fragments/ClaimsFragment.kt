package com.reclaim.mobile.screens.fragments

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.models.Claim
import com.reclaim.mobile.screens.activities.ItemDetailActivity
import com.reclaim.mobile.screens.adapters.ClaimAdapter
import kotlinx.coroutines.launch

class ClaimsFragment : Fragment() {
    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_claims, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val repository = MobileRepository(requireContext())
        val stats: TextView = view.findViewById(R.id.textClaimStats)
        val progress: ProgressBar = view.findViewById(R.id.progressClaims)
        val empty: TextView = view.findViewById(R.id.textClaimsEmpty)
        val recycler: RecyclerView = view.findViewById(R.id.recyclerClaims)
        val swipe: SwipeRefreshLayout = view.findViewById(R.id.swipeClaims)

        lateinit var loadClaims: () -> Unit

        val adapter = ClaimAdapter(
            onPrimaryAction = { claim -> handlePrimaryAction(repository, claim, progress, swipe, loadClaims) },
            onSecondaryAction = { claim ->
                startActivity(Intent(requireContext(), ItemDetailActivity::class.java).putExtra(ItemDetailActivity.EXTRA_ITEM_ID, claim.itemId))
            }
        )

        recycler.layoutManager = LinearLayoutManager(requireContext())
        recycler.adapter = adapter

        loadClaims = {
            progress.visibility = View.VISIBLE
            lifecycleScope.launch {
                repository.claims()
                    .onSuccess { response ->
                        stats.text = "Total: ${response.stats.total} • Pending: ${response.stats.pending} • Approved: ${response.stats.approved} • Completed: ${response.stats.completed}"
                        adapter.submitList(response.claims)
                        empty.visibility = if (response.claims.isEmpty()) View.VISIBLE else View.GONE
                    }
                    .onFailure {
                        empty.visibility = View.VISIBLE
                        empty.text = ApiErrorParser.message(it)
                    }

                progress.visibility = View.GONE
                swipe.isRefreshing = false
            }
        }

        swipe.setOnRefreshListener { loadClaims() }
        loadClaims()
    }

    private fun handlePrimaryAction(
        repository: MobileRepository,
        claim: Claim,
        progress: View,
        swipe: SwipeRefreshLayout,
        onReload: () -> Unit
    ) {
        val action = if (claim.status == "pending") "cancel" else "complete"
        AlertDialog.Builder(requireContext())
            .setTitle("Confirm")
            .setMessage("Do you want to $action this claim?")
            .setPositiveButton("Yes") { _, _ ->
                progress.visibility = View.VISIBLE
                lifecycleScope.launch {
                    val result = if (claim.status == "pending") {
                        repository.cancelClaim(claim.id)
                    } else {
                        repository.completeClaim(claim.id)
                    }

                    result.onSuccess {
                        Toast.makeText(requireContext(), "Claim updated successfully.", Toast.LENGTH_LONG).show()
                        swipe.isRefreshing = true
                        onReload()
                    }.onFailure {
                        Toast.makeText(requireContext(), ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                    }

                    progress.visibility = View.GONE
                    swipe.isRefreshing = false
                }
            }
            .setNegativeButton("No", null)
            .show()
    }
}
